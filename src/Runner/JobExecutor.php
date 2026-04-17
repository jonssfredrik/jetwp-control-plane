<?php

declare(strict_types=1);

namespace JetWP\Control\Runner;

use JetWP\Control\Models\Job;
use JetWP\Control\Models\Server;
use JetWP\Control\Models\Site;
use JetWP\Control\Services\ActivityLogService;
use PDO;
use RuntimeException;
use Throwable;

final class JobExecutor
{
    private readonly LocalDevExecutor $localDevExecutor;

    public function __construct(
        private readonly PDO $db,
        private readonly SshClient $sshClient,
        private readonly int $defaultTimeoutSeconds = 300,
        private readonly ?ActivityLogService $activityLog = null,
        ?LocalDevExecutor $localDevExecutor = null,
    ) {
        $this->localDevExecutor = $localDevExecutor ?? new LocalDevExecutor();
    }

    public function buildCommand(Job $job): string
    {
        [$site, $server] = $this->loadContext($job);

        if ($this->localDevExecutor->supports($site, $server, $job->type)) {
            return $this->localDevExecutor->describe($job);
        }

        return $this->commandBuilder($site, $server)->build($job->type, $job->params ?? []);
    }

    public function execute(Job $job, bool $dryRun = false, ?int $timeoutSeconds = null): ExecutionResult
    {
        return $this->run($job, $dryRun, $timeoutSeconds, true);
    }

    public function executeClaimed(Job $job, bool $dryRun = false, ?int $timeoutSeconds = null): ExecutionResult
    {
        return $this->run($job, $dryRun, $timeoutSeconds, false);
    }

    private function run(Job $job, bool $dryRun, ?int $timeoutSeconds, bool $markRunning): ExecutionResult
    {
        [$site, $server] = $this->loadContext($job);
        $useLocalDev = $this->localDevExecutor->supports($site, $server, $job->type);
        $command = $useLocalDev
            ? $this->localDevExecutor->describe($job)
            : $this->commandBuilder($site, $server)->build($job->type, $job->params ?? []);

        if ($dryRun) {
            return ExecutionResult::dryRun($command);
        }

        if ($markRunning) {
            Job::markRunning($this->db, $job->id);
        }

        try {
            if ($useLocalDev) {
                $result = $this->localDevExecutor->execute(
                    $site,
                    $server,
                    $job,
                    $timeoutSeconds ?? $this->defaultTimeoutSeconds
                );
            } else {
                $result = $this->sshClient->execute(
                    $server,
                    $command,
                    $timeoutSeconds ?? $this->defaultTimeoutSeconds
                );
            }
        } catch (Throwable $exception) {
            $failedJob = Job::markFailed(
                $this->db,
                $job->id,
                null,
                $exception->getMessage(),
                null
            );
            $this->activityLog?->logJobExecution($failedJob ?? $job, 'failed', [
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        if ($result->successful()) {
            $completedJob = Job::markCompleted(
                $this->db,
                $job->id,
                $result->stdout !== '' ? $result->stdout : null,
                $result->stderr !== '' ? $result->stderr : null,
                $result->durationMs
            );
            $this->activityLog?->logJobExecution($completedJob ?? $job, 'completed', [
                'exit_code' => $result->exitCode,
                'duration_ms' => $result->durationMs,
                'timed_out' => $result->timedOut,
            ]);

            return $result;
        }

        $failedJob = Job::markFailed(
            $this->db,
            $job->id,
            $result->stdout !== '' ? $result->stdout : null,
            $result->stderr !== '' ? $result->stderr : null,
            $result->durationMs
        );
        $this->activityLog?->logJobExecution($failedJob ?? $job, 'failed', [
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'timed_out' => $result->timedOut,
        ]);

        return $result;
    }

    /**
     * @return array{0: Site, 1: Server}
     */
    private function loadContext(Job $job): array
    {
        $site = Site::findById($this->db, $job->siteId);
        if (!$site instanceof Site) {
            throw new RuntimeException(sprintf('Site %s was not found for job %s.', $job->siteId, $job->id));
        }

        $server = Server::findById($this->db, $site->serverId);
        if (!$server instanceof Server) {
            throw new RuntimeException(sprintf('Server %d was not found for job %s.', $site->serverId, $job->id));
        }

        return [$site, $server];
    }

    private function commandBuilder(Site $site, Server $server): CommandBuilder
    {
        return new CommandBuilder(
            wpCli: $server->wpCliPath,
            wpPath: $site->wpPath,
            siteUrl: $site->url,
        );
    }
}
