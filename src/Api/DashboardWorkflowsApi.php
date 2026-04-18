<?php

declare(strict_types=1);

namespace JetWP\Control\Api;

use JetWP\Control\Auth\Authorization;
use JetWP\Control\Auth\AuthorizationException;
use JetWP\Control\Auth\Csrf;
use JetWP\Control\Jobs\JobValidationException;
use JetWP\Control\Models\Site;
use JetWP\Control\Models\User;
use JetWP\Control\Models\Workflow;
use JetWP\Control\Models\WorkflowEdge;
use JetWP\Control\Models\WorkflowNode;
use JetWP\Control\Models\WorkflowRun;
use JetWP\Control\Models\WorkflowRunStep;
use JetWP\Control\Services\ActivityLogService;
use JetWP\Control\Services\WorkflowRunService;
use JetWP\Control\Services\WorkflowService;
use JetWP\Control\Workflows\WorkflowCatalog;
use PDO;
use Throwable;

final class DashboardWorkflowsApi
{
    private readonly Authorization $authorization;

    public function __construct(
        private readonly PDO $db,
        private readonly Csrf $csrf,
        private readonly ?User $user,
        private readonly WorkflowService $workflowService,
        private readonly WorkflowRunService $workflowRunService,
        private readonly ?ActivityLogService $activityLog = null,
    ) {
        $this->authorization = new Authorization();
    }

    public function handles(string $path): bool
    {
        return str_starts_with($path, '/dashboard/api/v1/workflows')
            || str_starts_with($path, '/dashboard/api/v1/workflow-runs');
    }

    public function dispatch(string $method, string $path): void
    {
        try {
            $this->authorization->ensureJobsAccess($this->user);
        } catch (AuthorizationException $exception) {
            $this->error(401, $exception->getMessage(), 'AUTH_REQUIRED');
        }

        if ($method === 'GET' && $path === '/dashboard/api/v1/workflows/catalog') {
            $this->catalog();
            return;
        }

        if ($method === 'GET' && $path === '/dashboard/api/v1/workflows') {
            $this->index();
            return;
        }

        if ($method === 'POST' && $path === '/dashboard/api/v1/workflows') {
            $this->create();
            return;
        }

        if ($method === 'GET' && preg_match('#^/dashboard/api/v1/workflows/([a-f0-9-]{36})$#i', $path, $matches) === 1) {
            $this->show(strtolower($matches[1]));
            return;
        }

        if ($method === 'PATCH' && preg_match('#^/dashboard/api/v1/workflows/([a-f0-9-]{36})$#i', $path, $matches) === 1) {
            $this->update(strtolower($matches[1]));
            return;
        }

        if ($method === 'POST' && preg_match('#^/dashboard/api/v1/workflows/([a-f0-9-]{36})/run$#i', $path, $matches) === 1) {
            $this->run(strtolower($matches[1]));
            return;
        }

        if ($method === 'GET' && preg_match('#^/dashboard/api/v1/workflow-runs/([a-f0-9-]{36})$#i', $path, $matches) === 1) {
            $this->showRun(strtolower($matches[1]));
            return;
        }

        if ($method === 'POST' && preg_match('#^/dashboard/api/v1/workflow-runs/([a-f0-9-]{36})/cancel$#i', $path, $matches) === 1) {
            $this->cancelRun(strtolower($matches[1]));
            return;
        }

        $this->error(404, 'Not found.', 'NOT_FOUND');
    }

    private function catalog(): void
    {
        $this->respond([
            'status' => 'ok',
            'data' => WorkflowCatalog::nodeTypes(),
        ]);
    }

    private function index(): void
    {
        $items = array_map(function (Workflow $workflow): array {
            return [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'description' => $workflow->description,
                'status' => $workflow->status,
                'created_at' => $workflow->createdAt,
                'updated_at' => $workflow->updatedAt,
            ];
        }, Workflow::all($this->db));

        $this->respond([
            'status' => 'ok',
            'data' => $items,
        ]);
    }

    private function show(string $id): void
    {
        $graph = $this->workflowService->loadGraph($id);
        if ($graph === null) {
            $this->error(404, 'Workflow not found.', 'NOT_FOUND');
        }

        $this->respond([
            'status' => 'ok',
            'data' => $this->serializeGraph($graph['workflow'], $graph['nodes'], $graph['edges']),
        ]);
    }

    private function create(): void
    {
        $payload = $this->readJsonBody(required: true);
        $this->ensureCsrf($payload);

        try {
            $workflow = $this->workflowService->create($payload, $this->user?->id);
        } catch (JobValidationException $exception) {
            $this->error(400, $exception->getMessage(), 'VALIDATION_ERROR', $exception->errors);
        } catch (Throwable) {
            $this->error(500, 'Failed to create workflow.', 'INTERNAL_ERROR');
        }

        $this->activityLog?->record($this->user?->id, null, 'workflow.created', [
            'workflow_id' => $workflow->id,
            'name' => $workflow->name,
        ]);

        $graph = $this->workflowService->loadGraph($workflow->id);

        $this->respond([
            'status' => 'ok',
            'data' => $this->serializeGraph($graph['workflow'], $graph['nodes'], $graph['edges']),
        ], 201);
    }

    private function update(string $id): void
    {
        $payload = $this->readJsonBody(required: true);
        $this->ensureCsrf($payload);

        try {
            $workflow = $this->workflowService->update($id, $payload);
        } catch (JobValidationException $exception) {
            $this->error(400, $exception->getMessage(), 'VALIDATION_ERROR', $exception->errors);
        } catch (Throwable) {
            $this->error(500, 'Failed to update workflow.', 'INTERNAL_ERROR');
        }

        if (!$workflow instanceof Workflow) {
            $this->error(404, 'Workflow not found.', 'NOT_FOUND');
        }

        $this->activityLog?->record($this->user?->id, null, 'workflow.updated', [
            'workflow_id' => $workflow->id,
            'name' => $workflow->name,
        ]);

        $graph = $this->workflowService->loadGraph($workflow->id);

        $this->respond([
            'status' => 'ok',
            'data' => $this->serializeGraph($graph['workflow'], $graph['nodes'], $graph['edges']),
        ]);
    }

    private function run(string $workflowId): void
    {
        $payload = $this->readJsonBody(required: true);
        $this->ensureCsrf($payload);

        $siteId = trim((string) ($payload['site_id'] ?? ''));
        if ($siteId === '') {
            $this->error(400, 'Site ID is required.', 'VALIDATION_ERROR', [
                'site_id' => ['Site ID is required.'],
            ]);
        }

        if (!Site::findById($this->db, $siteId) instanceof Site) {
            $this->error(404, 'Site not found.', 'NOT_FOUND');
        }

        try {
            $run = $this->workflowRunService->start($workflowId, $siteId);
        } catch (Throwable $exception) {
            $this->error(500, $exception->getMessage(), 'INTERNAL_ERROR');
        }

        $this->respond([
            'status' => 'ok',
            'data' => $this->serializeRun($run),
        ], 201);
    }

    private function showRun(string $id): void
    {
        $run = WorkflowRun::findById($this->db, $id);
        if (!$run instanceof WorkflowRun) {
            $this->error(404, 'Workflow run not found.', 'NOT_FOUND');
        }

        $this->respond([
            'status' => 'ok',
            'data' => $this->serializeRun($run, true),
        ]);
    }

    private function cancelRun(string $id): void
    {
        $payload = $this->readJsonBody(required: false);
        $this->ensureCsrf($payload);

        $run = WorkflowRun::findById($this->db, $id);
        if (!$run instanceof WorkflowRun) {
            $this->error(404, 'Workflow run not found.', 'NOT_FOUND');
        }

        if (!in_array($run->status, ['pending', 'running'], true)) {
            $this->error(409, 'Only pending or running workflow runs can be cancelled.', 'CONFLICT');
        }

        $cancelled = WorkflowRun::finish($this->db, $run->id, 'cancelled', $run->context);

        $this->respond([
            'status' => 'ok',
            'data' => $this->serializeRun($cancelled ?? $run, true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeGraph(Workflow $workflow, array $nodes, array $edges): array
    {
        return [
            'workflow' => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'description' => $workflow->description,
                'status' => $workflow->status,
                'created_at' => $workflow->createdAt,
                'updated_at' => $workflow->updatedAt,
            ],
            'nodes' => array_map(static function (WorkflowNode $node): array {
                return [
                    'id' => $node->id,
                    'node_key' => $node->nodeKey,
                    'type' => $node->type,
                    'label' => $node->label,
                    'config' => $node->config ?? (object) [],
                    'position_x' => $node->positionX,
                    'position_y' => $node->positionY,
                ];
            }, $nodes),
            'edges' => array_map(static function (WorkflowEdge $edge): array {
                return [
                    'id' => $edge->id,
                    'from_node_key' => $edge->fromNodeKey,
                    'to_node_key' => $edge->toNodeKey,
                    'edge_type' => $edge->edgeType,
                ];
            }, $edges),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(WorkflowRun $run, bool $includeSteps = false): array
    {
        $workflow = Workflow::findById($this->db, $run->workflowId);
        $site = Site::findById($this->db, $run->siteId);

        $data = [
            'id' => $run->id,
            'workflow_id' => $run->workflowId,
            'workflow_name' => $workflow?->name,
            'site_id' => $run->siteId,
            'site_label' => $site?->label,
            'status' => $run->status,
            'current_node_key' => $run->currentNodeKey,
            'context' => $run->context ?? (object) [],
            'started_at' => $run->startedAt,
            'completed_at' => $run->completedAt,
            'created_at' => $run->createdAt,
            'updated_at' => $run->updatedAt,
        ];

        if ($includeSteps) {
            $data['steps'] = array_map(static function (WorkflowRunStep $step): array {
                return [
                    'id' => $step->id,
                    'node_key' => $step->nodeKey,
                    'node_type' => $step->nodeType,
                    'status' => $step->status,
                    'job_id' => $step->jobId,
                    'input' => $step->input ?? (object) [],
                    'output' => $step->output ?? (object) [],
                    'error_output' => $step->errorOutput,
                    'started_at' => $step->startedAt,
                    'completed_at' => $step->completedAt,
                    'created_at' => $step->createdAt,
                ];
            }, WorkflowRunStep::forRun($this->db, $run->id));
        }

        return $data;
    }

    private function ensureCsrf(array $payload): void
    {
        $headers = $this->requestHeaders();
        $token = $headers['x-csrf-token'] ?? $payload['_token'] ?? $_POST['_token'] ?? null;

        if (!$this->csrf->validate(is_string($token) ? $token : null)) {
            $this->error(419, 'Invalid CSRF token.', 'AUTH_REQUIRED');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonBody(bool $required): array
    {
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || trim($rawBody) === '') {
            if ($required) {
                $this->error(400, 'Request body is required.', 'VALIDATION_ERROR', [
                    'body' => ['Request body is required.'],
                ]);
            }

            return [];
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || (array_is_list($payload) && $payload !== [])) {
            $this->error(400, 'Request body must be a JSON object.', 'VALIDATION_ERROR', [
                'body' => ['Request body must be a JSON object.'],
            ]);
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                $normalized = [];
                foreach ($headers as $name => $value) {
                    $normalized[strtolower((string) $name)] = (string) $value;
                }

                return $normalized;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[strtolower($name)] = (string) $value;
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    private function error(int $status, string $message, string $code, array $errors = []): never
    {
        $payload = [
            'status' => 'error',
            'message' => $message,
            'code' => $code,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        $this->respond($payload, $status);
    }
}
