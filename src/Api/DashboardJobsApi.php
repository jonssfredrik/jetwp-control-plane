<?php

declare(strict_types=1);

namespace JetWP\Control\Api;

use JetWP\Control\Auth\Authorization;
use JetWP\Control\Auth\AuthorizationException;
use InvalidArgumentException;
use JetWP\Control\Auth\Csrf;
use JetWP\Control\Jobs\JobFactory;
use JetWP\Control\Jobs\JobValidationException;
use JetWP\Control\Jobs\JobValidator;
use JetWP\Control\Models\Job;
use JetWP\Control\Models\User;
use JetWP\Control\Services\ActivityLogService;
use PDO;
use Throwable;

final class DashboardJobsApi
{
    private readonly Authorization $authorization;
    private readonly CreateJobController $createJobController;
    private readonly JobFactory $jobFactory;

    public function __construct(
        private readonly PDO $db,
        private readonly Csrf $csrf,
        private readonly ?User $user,
        private readonly ?ActivityLogService $activityLog = null
    ) {
        $validator = new JobValidator($this->db);
        $this->authorization = new Authorization();
        $this->jobFactory = new JobFactory($this->db);
        $this->createJobController = new CreateJobController($validator, $this->jobFactory);
    }

    public function handles(string $path): bool
    {
        return str_starts_with($path, '/dashboard/api/v1/jobs');
    }

    public function dispatch(string $method, string $path): void
    {
        try {
            $this->authorization->ensureJobsAccess($this->user);
        } catch (AuthorizationException $exception) {
            $this->error(401, $exception->getMessage(), 'AUTH_REQUIRED');
        }

        if ($method === 'GET' && $path === '/dashboard/api/v1/jobs') {
            $this->index();
            return;
        }

        if ($method === 'POST' && $path === '/dashboard/api/v1/jobs') {
            $this->create();
            return;
        }

        if ($method === 'GET' && preg_match('#^/dashboard/api/v1/jobs/([a-f0-9-]{36})$#i', $path, $matches) === 1) {
            $this->show(strtolower($matches[1]));
            return;
        }

        if ($method === 'POST' && preg_match('#^/dashboard/api/v1/jobs/([a-f0-9-]{36})/retry$#i', $path, $matches) === 1) {
            $this->retry(strtolower($matches[1]));
            return;
        }

        if ($method === 'POST' && preg_match('#^/dashboard/api/v1/jobs/([a-f0-9-]{36})/cancel$#i', $path, $matches) === 1) {
            $this->cancel(strtolower($matches[1]));
            return;
        }

        $this->error(404, 'Not found.', 'NOT_FOUND');
    }

    private function index(): void
    {
        $filters = [
            'status' => trim((string) ($_GET['status'] ?? '')),
            'type' => trim((string) ($_GET['type'] ?? '')),
            'site_id' => trim((string) ($_GET['site_id'] ?? '')),
        ];

        $jobs = Job::list($this->db, $filters);
        $data = array_map(
            static fn (Job $job): array => $job->toArray(),
            $jobs
        );

        $this->respond([
            'status' => 'ok',
            'data' => $data,
        ]);
    }

    private function show(string $id): void
    {
        $job = Job::findById($this->db, $id);
        if (!$job instanceof Job) {
            $this->error(404, 'Job not found.', 'NOT_FOUND');
        }

        $this->respond([
            'status' => 'ok',
            'data' => $job->toArray(true),
        ]);
    }

    private function create(): void
    {
        $payload = $this->readJsonBody(required: true);
        $this->ensureCsrf($payload);

        try {
            $this->authorization->ensureJobTypeAllowed($this->user, trim((string) ($payload['type'] ?? '')));
        } catch (AuthorizationException $exception) {
            $this->error(403, $exception->getMessage(), 'FORBIDDEN');
        }

        try {
            $job = $this->createJobController->handle($payload);
        } catch (JobValidationException $exception) {
            $this->error(400, $exception->getMessage(), 'VALIDATION_ERROR', $exception->errors);
        } catch (Throwable) {
            $this->error(500, 'Failed to create job.', 'INTERNAL_ERROR');
        }

        $this->activityLog?->logJobCreated($job, $this->user, 'dashboard.api');

        $this->respond([
            'status' => 'ok',
            'data' => $job->toArray(true),
        ], 201);
    }

    private function retry(string $id): void
    {
        $payload = $this->readJsonBody(required: false);
        $this->ensureCsrf($payload);

        $job = Job::findById($this->db, $id);
        if (!$job instanceof Job) {
            $this->error(404, 'Job not found.', 'NOT_FOUND');
        }

        try {
            $this->authorization->ensureJobTypeAllowed($this->user, $job->type);
        } catch (AuthorizationException $exception) {
            $this->error(403, $exception->getMessage(), 'FORBIDDEN');
        }

        try {
            $retried = $this->jobFactory->retry($job);
        } catch (InvalidArgumentException $exception) {
            $this->error(409, $exception->getMessage(), 'CONFLICT');
        } catch (Throwable) {
            $this->error(500, 'Failed to retry job.', 'INTERNAL_ERROR');
        }

        $this->activityLog?->logJobCreated($retried, $this->user, 'dashboard.api.retry', [
            'parent_job_id' => $job->id,
        ]);

        $this->respond([
            'status' => 'ok',
            'data' => $retried->toArray(true),
        ], 201);
    }

    private function cancel(string $id): void
    {
        $payload = $this->readJsonBody(required: false);
        $this->ensureCsrf($payload);

        try {
            $job = Job::cancel($this->db, $id);
        } catch (InvalidArgumentException $exception) {
            $this->error(409, $exception->getMessage(), 'CONFLICT');
        } catch (Throwable) {
            $this->error(500, 'Failed to cancel job.', 'INTERNAL_ERROR');
        }

        if (!$job instanceof Job) {
            $this->error(404, 'Job not found.', 'NOT_FOUND');
        }

        $this->respond([
            'status' => 'ok',
            'data' => $job->toArray(true),
        ]);
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
        if (!is_array($payload)) {
            $this->error(400, 'Request body must be a JSON object.', 'VALIDATION_ERROR', [
                'body' => ['Request body must be a JSON object.'],
            ]);
        }

        if (trim($rawBody) === '[]' || (array_is_list($payload) && $payload !== [])) {
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
