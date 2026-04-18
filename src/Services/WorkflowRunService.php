<?php

declare(strict_types=1);

namespace JetWP\Control\Services;

use JetWP\Control\Jobs\JobFactory;
use JetWP\Control\Models\Job;
use JetWP\Control\Models\Site;
use JetWP\Control\Models\Workflow;
use JetWP\Control\Models\WorkflowEdge;
use JetWP\Control\Models\WorkflowNode;
use JetWP\Control\Models\WorkflowRun;
use JetWP\Control\Models\WorkflowRunStep;
use JetWP\Control\Services\ActivityLogService;
use PDO;
use RuntimeException;

final class WorkflowRunService
{
    public function __construct(
        private readonly PDO $db,
        private readonly WorkflowService $workflowService,
        private readonly SiteInventoryService $siteInventoryService,
        private readonly JobFactory $jobFactory,
        private readonly JobDispatchService $jobDispatchService,
        private readonly ?ActivityLogService $activityLog = null,
    ) {
    }

    public function start(string $workflowId, string $siteId): WorkflowRun
    {
        $graph = $this->workflowService->loadGraph($workflowId);
        if ($graph === null) {
            throw new RuntimeException('Workflow not found.');
        }

        $workflow = $graph['workflow'];
        $site = Site::findById($this->db, $siteId);
        if (!$site instanceof Site) {
            throw new RuntimeException('Site not found.');
        }

        $startNode = $this->findStartNode($graph['nodes']);

        $run = WorkflowRun::create($this->db, [
            'workflow_id' => $workflow->id,
            'site_id' => $site->id,
            'status' => 'running',
            'current_node_key' => $startNode->nodeKey,
            'context' => ['site_id' => $site->id, 'workflow_id' => $workflow->id],
        ]);

        $this->activityLog?->record(null, $site->id, 'workflow.run.started', [
            'workflow_id' => $workflow->id,
            'workflow_run_id' => $run->id,
            'workflow_name' => $workflow->name,
        ]);

        return $this->process($run);
    }

    public function process(WorkflowRun $run): WorkflowRun
    {
        $graph = $this->workflowService->loadGraph($run->workflowId);
        if ($graph === null) {
            throw new RuntimeException('Workflow not found.');
        }

        $nodes = [];
        foreach ($graph['nodes'] as $node) {
            $nodes[$node->nodeKey] = $node;
        }

        $edges = [];
        foreach ($graph['edges'] as $edge) {
            $edges[$edge->fromNodeKey][$edge->edgeType] = $edge->toNodeKey;
        }

        $site = Site::findById($this->db, $run->siteId);
        if (!$site instanceof Site) {
            throw new RuntimeException('Site not found for workflow run.');
        }

        $inventory = $this->siteInventoryService->snapshotForSite($site);
        $currentNodeKey = $run->currentNodeKey;
        $steps = 0;

        while ($currentNodeKey !== null) {
            if ($steps > 100) {
                $run = WorkflowRun::finish($this->db, $run->id, 'failed', $run->context);
                throw new RuntimeException('Workflow exceeded the maximum number of steps.');
            }
            $steps++;

            $node = $nodes[$currentNodeKey] ?? null;
            if (!$node instanceof WorkflowNode) {
                $run = WorkflowRun::finish($this->db, $run->id, 'failed', $run->context);
                throw new RuntimeException('Workflow node not found during processing.');
            }

            if ($node->type === 'start') {
                WorkflowRunStep::create($this->db, [
                    'workflow_run_id' => $run->id,
                    'node_key' => $node->nodeKey,
                    'node_type' => $node->type,
                    'status' => 'completed',
                    'output' => ['message' => 'Workflow started.'],
                ]);
                $currentNodeKey = $edges[$node->nodeKey]['next'] ?? null;
                $run = WorkflowRun::updateState($this->db, $run->id, 'running', $currentNodeKey, $run->context);
                continue;
            }

            if ($node->type === 'end') {
                WorkflowRunStep::create($this->db, [
                    'workflow_run_id' => $run->id,
                    'node_key' => $node->nodeKey,
                    'node_type' => $node->type,
                    'status' => 'completed',
                    'output' => ['message' => 'Workflow ended.'],
                ]);
                $run = WorkflowRun::finish($this->db, $run->id, 'completed', $run->context);
                $this->activityLog?->record(null, $site->id, 'workflow.run.completed', [
                    'workflow_id' => $run->workflowId,
                    'workflow_run_id' => $run->id,
                ]);

                return $run;
            }

            if (str_starts_with($node->type, 'condition.')) {
                try {
                    [$passed, $output] = $this->evaluateCondition($node, $run, $inventory);
                } catch (\Throwable $exception) {
                    WorkflowRunStep::create($this->db, [
                        'workflow_run_id' => $run->id,
                        'node_key' => $node->nodeKey,
                        'node_type' => $node->type,
                        'status' => 'failed',
                        'error_output' => $exception->getMessage(),
                    ]);
                    return WorkflowRun::finish($this->db, $run->id, 'failed', $run->context) ?? $run;
                }
                WorkflowRunStep::create($this->db, [
                    'workflow_run_id' => $run->id,
                    'node_key' => $node->nodeKey,
                    'node_type' => $node->type,
                    'status' => 'completed',
                    'output' => $output + ['result' => $passed],
                ]);
                $currentNodeKey = $edges[$node->nodeKey][$passed ? 'true' : 'false'] ?? null;
                $run = WorkflowRun::updateState($this->db, $run->id, 'running', $currentNodeKey, $run->context);
                continue;
            }

            try {
                [$stepStatus, $output, $errorOutput] = $this->executeActionNode($node, $site, $inventory, $run);
            } catch (\Throwable $exception) {
                WorkflowRunStep::create($this->db, [
                    'workflow_run_id' => $run->id,
                    'node_key' => $node->nodeKey,
                    'node_type' => $node->type,
                    'status' => 'failed',
                    'error_output' => $exception->getMessage(),
                ]);
                $run = WorkflowRun::finish($this->db, $run->id, 'failed', $run->context) ?? $run;
                $this->activityLog?->record(null, $site->id, 'workflow.run.failed', [
                    'workflow_id' => $run->workflowId,
                    'workflow_run_id' => $run->id,
                    'node_key' => $node->nodeKey,
                    'node_type' => $node->type,
                    'error_output' => $exception->getMessage(),
                ]);

                return $run;
            }
            WorkflowRunStep::create($this->db, [
                'workflow_run_id' => $run->id,
                'node_key' => $node->nodeKey,
                'node_type' => $node->type,
                'status' => $stepStatus,
                'output' => $output,
                'error_output' => $errorOutput,
            ]);

            if ($stepStatus === 'failed') {
                $run = WorkflowRun::finish($this->db, $run->id, 'failed', $run->context);
                $this->activityLog?->record(null, $site->id, 'workflow.run.failed', [
                    'workflow_id' => $run->workflowId,
                    'workflow_run_id' => $run->id,
                    'node_key' => $node->nodeKey,
                    'node_type' => $node->type,
                    'error_output' => $errorOutput,
                ]);

                return $run;
            }

            $currentNodeKey = $edges[$node->nodeKey]['next'] ?? null;
            $run = WorkflowRun::updateState($this->db, $run->id, 'running', $currentNodeKey, $run->context);
        }

        return WorkflowRun::finish($this->db, $run->id, 'completed', $run->context)
            ?? $run;
    }

    /**
     * @param list<WorkflowNode> $nodes
     */
    private function findStartNode(array $nodes): WorkflowNode
    {
        foreach ($nodes as $node) {
            if ($node->type === 'start') {
                return $node;
            }
        }

        throw new RuntimeException('Workflow start node was not found.');
    }

    /**
     * @param array{
     *     latest_telemetry: array{id:int,site_id:string,payload:array,received_at:string}|null,
     *     history: list<array{id:int,site_id:string,payload:array,received_at:string}>,
     *     core: array{current_version:?string,available_update:?string,rollback_version:?string,has_update:bool},
     *     plugins: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string,file:?string}>,
     *     themes: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string}>,
     *     summary: array{plugin_count:int,theme_count:int,plugin_updates:int,theme_updates:int}
     * } $inventory
     * @return array{0:bool,1:array<string,mixed>}
     */
    private function evaluateCondition(WorkflowNode $node, WorkflowRun $run, array $inventory): array
    {
        $config = $node->config ?? [];

        return match ($node->type) {
            'condition.previous_step_succeeded' => $this->conditionPreviousStepSucceeded($run),
            'condition.plugin_updates_available' => $this->conditionPluginUpdatesAvailable($config, $inventory),
            'condition.theme_updates_available' => $this->conditionThemeUpdatesAvailable($config, $inventory),
            'condition.core_update_available' => [
                $inventory['core']['has_update'],
                ['available_update' => $inventory['core']['available_update']],
            ],
            default => throw new RuntimeException('Unsupported condition node type: ' . $node->type),
        };
    }

    /**
     * @param array{
     *     latest_telemetry: array{id:int,site_id:string,payload:array,received_at:string}|null,
     *     history: list<array{id:int,site_id:string,payload:array,received_at:string}>,
     *     core: array{current_version:?string,available_update:?string,rollback_version:?string,has_update:bool},
     *     plugins: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string,file:?string}>,
     *     themes: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string}>,
     *     summary: array{plugin_count:int,theme_count:int,plugin_updates:int,theme_updates:int}
     * } $inventory
     * @return array{0:string,1:array<string,mixed>,2:?string}
     */
    private function executeActionNode(WorkflowNode $node, Site $site, array $inventory, WorkflowRun $run): array
    {
        $jobsToRun = $this->jobsForActionNode($node, $site, $inventory);
        $output = [
            'jobs' => [],
            'skipped' => $jobsToRun['skipped'],
            'node_label' => $node->label,
        ];

        foreach ($jobsToRun['jobs'] as $jobAttributes) {
            $job = $this->jobFactory->create($jobAttributes);
            $job = $this->jobDispatchService->dispatch($job, 'workflow_run:' . $run->id);
            $output['jobs'][] = [
                'job_id' => $job->id,
                'type' => $job->type,
                'status' => $job->status,
                'params' => $job->params ?? (object) [],
            ];

            if ($job->status !== Job::STATUS_COMPLETED) {
                return [
                    'failed',
                    $output,
                    $job->errorOutput ?? ('Job ' . $job->id . ' did not complete successfully.'),
                ];
            }
        }

        return ['completed', $output, null];
    }

    /**
     * @param array{
     *     latest_telemetry: array{id:int,site_id:string,payload:array,received_at:string}|null,
     *     history: list<array{id:int,site_id:string,payload:array,received_at:string}>,
     *     core: array{current_version:?string,available_update:?string,rollback_version:?string,has_update:bool},
     *     plugins: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string,file:?string}>,
     *     themes: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string}>,
     *     summary: array{plugin_count:int,theme_count:int,plugin_updates:int,theme_updates:int}
     * } $inventory
     * @return array{jobs:list<array<string,mixed>>,skipped:list<string>}
     */
    private function jobsForActionNode(WorkflowNode $node, Site $site, array $inventory): array
    {
        $base = [
            'site_id' => $site->id,
            'priority' => 5,
            'max_attempts' => 3,
            'scheduled_at' => null,
            'created_by' => 'workflow',
        ];

        $config = $node->config ?? [];
        $jobs = [];
        $skipped = [];

        switch ($node->type) {
            case 'cache.flush':
            case 'db.optimize':
            case 'translations.update':
            case 'security.integrity':
                $jobs[] = $base + ['type' => $node->type, 'params' => []];
                break;

            case 'core.update':
                if ($inventory['core']['available_update'] === null && $this->nullableString($config['version'] ?? null) === null) {
                    $skipped[] = 'No core update available.';
                    break;
                }

                $params = [];
                $version = $this->nullableString($config['version'] ?? null) ?? $inventory['core']['available_update'];
                if ($version !== null) {
                    $params['version'] = $version;
                }
                $jobs[] = $base + ['type' => 'core.update', 'params' => $params];
                break;

            case 'plugin.update.selected':
                foreach ($config['plugins'] ?? [] as $slug) {
                    if (!$this->pluginHasUpdate((string) $slug, $inventory)) {
                        $skipped[] = (string) $slug . ' (no update available)';
                        continue;
                    }
                    $jobs[] = $base + ['type' => 'plugin.update', 'params' => ['slug' => (string) $slug]];
                }
                break;

            case 'plugin.update.all_with_updates':
                foreach ($inventory['plugins'] as $plugin) {
                    if ($plugin['update_available'] === null) {
                        continue;
                    }
                    $jobs[] = $base + ['type' => 'plugin.update', 'params' => ['slug' => $plugin['slug']]];
                }
                if ($jobs === []) {
                    $skipped[] = 'No plugin updates available.';
                }
                break;

            case 'plugin.update.all_except':
                $excluded = array_fill_keys(array_map('strval', $config['exclude_plugins'] ?? []), true);
                foreach ($inventory['plugins'] as $plugin) {
                    if ($plugin['update_available'] === null) {
                        continue;
                    }
                    if (isset($excluded[$plugin['slug']])) {
                        $skipped[] = $plugin['slug'] . ' (excluded)';
                        continue;
                    }
                    $jobs[] = $base + ['type' => 'plugin.update', 'params' => ['slug' => $plugin['slug']]];
                }
                if ($jobs === []) {
                    $skipped[] = 'No plugin updates available after exclusions.';
                }
                break;

            case 'theme.update.selected':
                foreach ($config['themes'] ?? [] as $slug) {
                    if (!$this->themeHasUpdate((string) $slug, $inventory)) {
                        $skipped[] = (string) $slug . ' (no update available)';
                        continue;
                    }
                    $jobs[] = $base + ['type' => 'theme.update', 'params' => ['slug' => (string) $slug]];
                }
                break;

            case 'theme.update.all_with_updates':
                foreach ($inventory['themes'] as $theme) {
                    if ($theme['update_available'] === null) {
                        continue;
                    }
                    $jobs[] = $base + ['type' => 'theme.update', 'params' => ['slug' => $theme['slug']]];
                }
                if ($jobs === []) {
                    $skipped[] = 'No theme updates available.';
                }
                break;

            case 'theme.update.all_except':
                $excluded = array_fill_keys(array_map('strval', $config['exclude_themes'] ?? []), true);
                foreach ($inventory['themes'] as $theme) {
                    if ($theme['update_available'] === null) {
                        continue;
                    }
                    if (isset($excluded[$theme['slug']])) {
                        $skipped[] = $theme['slug'] . ' (excluded)';
                        continue;
                    }
                    $jobs[] = $base + ['type' => 'theme.update', 'params' => ['slug' => $theme['slug']]];
                }
                if ($jobs === []) {
                    $skipped[] = 'No theme updates available after exclusions.';
                }
                break;

            default:
                throw new RuntimeException('Unsupported workflow action node: ' . $node->type);
        }

        return ['jobs' => $jobs, 'skipped' => $skipped];
    }

    /**
     * @param array{
     *     latest_telemetry: array{id:int,site_id:string,payload:array,received_at:string}|null,
     *     history: list<array{id:int,site_id:string,payload:array,received_at:string}>,
     *     core: array{current_version:?string,available_update:?string,rollback_version:?string,has_update:bool},
     *     plugins: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string,file:?string}>,
     *     themes: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string}>,
     *     summary: array{plugin_count:int,theme_count:int,plugin_updates:int,theme_updates:int}
     * } $inventory
     * @return array{0:bool,1:array<string,mixed>}
     */
    private function conditionPluginUpdatesAvailable(array $config, array $inventory): array
    {
        $mode = (string) ($config['mode'] ?? 'all_with_updates');

        if ($mode === 'selected') {
            $matching = array_values(array_filter(
                $inventory['plugins'],
                static fn (array $plugin): bool => in_array($plugin['slug'], array_map('strval', $config['plugins'] ?? []), true)
                    && $plugin['update_available'] !== null
            ));

            return [$matching !== [], ['matching_plugins' => array_column($matching, 'slug')]];
        }

        if ($mode === 'all_except') {
            $excluded = array_fill_keys(array_map('strval', $config['exclude_plugins'] ?? []), true);
            $matching = array_values(array_filter(
                $inventory['plugins'],
                static fn (array $plugin): bool => $plugin['update_available'] !== null
                    && !isset($excluded[$plugin['slug']])
            ));

            return [$matching !== [], ['matching_plugins' => array_column($matching, 'slug')]];
        }

        $matching = array_values(array_filter(
            $inventory['plugins'],
            static fn (array $plugin): bool => $plugin['update_available'] !== null
        ));

        return [$matching !== [], ['matching_plugins' => array_column($matching, 'slug')]];
    }

    /**
     * @param array{
     *     latest_telemetry: array{id:int,site_id:string,payload:array,received_at:string}|null,
     *     history: list<array{id:int,site_id:string,payload:array,received_at:string}>,
     *     core: array{current_version:?string,available_update:?string,rollback_version:?string,has_update:bool},
     *     plugins: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string,file:?string}>,
     *     themes: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string}>,
     *     summary: array{plugin_count:int,theme_count:int,plugin_updates:int,theme_updates:int}
     * } $inventory
     * @return array{0:bool,1:array<string,mixed>}
     */
    private function conditionThemeUpdatesAvailable(array $config, array $inventory): array
    {
        $mode = (string) ($config['mode'] ?? 'all_with_updates');

        if ($mode === 'selected') {
            $matching = array_values(array_filter(
                $inventory['themes'],
                static fn (array $theme): bool => in_array($theme['slug'], array_map('strval', $config['themes'] ?? []), true)
                    && $theme['update_available'] !== null
            ));

            return [$matching !== [], ['matching_themes' => array_column($matching, 'slug')]];
        }

        if ($mode === 'all_except') {
            $excluded = array_fill_keys(array_map('strval', $config['exclude_themes'] ?? []), true);
            $matching = array_values(array_filter(
                $inventory['themes'],
                static fn (array $theme): bool => $theme['update_available'] !== null
                    && !isset($excluded[$theme['slug']])
            ));

            return [$matching !== [], ['matching_themes' => array_column($matching, 'slug')]];
        }

        $matching = array_values(array_filter(
            $inventory['themes'],
            static fn (array $theme): bool => $theme['update_available'] !== null
        ));

        return [$matching !== [], ['matching_themes' => array_column($matching, 'slug')]];
    }

    /**
     * @return array{0:bool,1:array<string,mixed>}
     */
    private function conditionPreviousStepSucceeded(WorkflowRun $run): array
    {
        $step = WorkflowRunStep::latestForRun($this->db, $run->id);
        if (!$step instanceof WorkflowRunStep) {
            return [true, ['message' => 'No previous step was found.']];
        }

        return [$step->status === 'completed', ['previous_step_status' => $step->status, 'node_key' => $step->nodeKey]];
    }

    /**
     * @param array{
     *     latest_telemetry: array{id:int,site_id:string,payload:array,received_at:string}|null,
     *     history: list<array{id:int,site_id:string,payload:array,received_at:string}>,
     *     core: array{current_version:?string,available_update:?string,rollback_version:?string,has_update:bool},
     *     plugins: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string,file:?string}>,
     *     themes: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string}>,
     *     summary: array{plugin_count:int,theme_count:int,plugin_updates:int,theme_updates:int}
     * } $inventory
     */
    private function pluginHasUpdate(string $slug, array $inventory): bool
    {
        foreach ($inventory['plugins'] as $plugin) {
            if ($plugin['slug'] === $slug && $plugin['update_available'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{
     *     latest_telemetry: array{id:int,site_id:string,payload:array,received_at:string}|null,
     *     history: list<array{id:int,site_id:string,payload:array,received_at:string}>,
     *     core: array{current_version:?string,available_update:?string,rollback_version:?string,has_update:bool},
     *     plugins: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string,file:?string}>,
     *     themes: list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string}>,
     *     summary: array{plugin_count:int,theme_count:int,plugin_updates:int,theme_updates:int}
     * } $inventory
     */
    private function themeHasUpdate(string $slug, array $inventory): bool
    {
        foreach ($inventory['themes'] as $theme) {
            if ($theme['slug'] === $slug && $theme['update_available'] !== null) {
                return true;
            }
        }

        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
