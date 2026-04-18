<?php

declare(strict_types=1);

namespace JetWP\Control\Queue;

use JetWP\Control\Models\Job;
use JetWP\Control\Runner\JobExecutor;
use JetWP\Control\Services\AlertService;
use JetWP\Control\Services\ActivityLogService;
use JetWP\Control\Support\DebugLogger;
use PDO;
use Throwable;

final class Worker
{
    public function __construct(
        private readonly PDO $db,
        private readonly JobExecutor $executor,
        private readonly ConcurrencyManager $concurrencyManager,
        private readonly int $pollIntervalSeconds = 5,
        private readonly int $defaultTimeoutSeconds = 300,
        private readonly ?AlertService $alertService = null,
        private readonly ?ActivityLogService $activityLog = null,
        private readonly ?DebugLogger $debugLogger = null,
    ) {
    }

    public function run(bool $once = false): int
    {
        $processed = 0;

        do {
            $didWork = $this->runOnce();
            if ($didWork) {
                $processed++;
            }

            if ($once) {
                break;
            }

            if (!$didWork) {
                sleep(max(1, $this->pollIntervalSeconds));
            }
        } while (true);

        return $processed;
    }

    public function runOnce(): bool
    {
        $job = Job::claimNextPendingForSsh($this->db);
        if (!$job instanceof Job) {
            return false;
        }

        $this->activityLog?->logJobWorkerEvent($job, 'claimed');
        $this->debugLogger?->info('Job claimed by queue worker.', [
            'job_id' => $job->id,
            'site_id' => $job->siteId,
            'type' => $job->type,
            'attempts' => $job->attempts,
        ]);

        if (!$this->concurrencyManager->hasCapacityForSite($job->siteId)) {
            Job::restorePending($this->db, $job->id);
            $this->activityLog?->logJobWorkerEvent($job, 'capacity_blocked');
            $this->debugLogger?->info('Job restored to pending because server capacity was exhausted.', [
                'job_id' => $job->id,
                'site_id' => $job->siteId,
            ]);
            return false;
        }

        try {
            $this->activityLog?->logJobWorkerEvent($job, 'execution_started');
            $this->debugLogger?->info('Queue worker started job execution.', [
                'job_id' => $job->id,
                'site_id' => $job->siteId,
                'timeout_seconds' => $this->defaultTimeoutSeconds,
            ]);
            $result = $this->executor->executeClaimed(
                $job,
                false,
                $this->defaultTimeoutSeconds
            );
        } catch (Throwable $exception) {
            $failedJob = Job::incrementAttempts($this->db, $job->id);
            $this->activityLog?->logJobWorkerEvent($failedJob ?? $job, 'exception', [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->debugLogger?->error('Queue worker caught exception while executing job.', [
                'job_id' => $job->id,
                'site_id' => $job->siteId,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            if ($failedJob instanceof Job && $failedJob->attempts < $failedJob->maxAttempts) {
                Job::rescheduleForRetry($this->db, $failedJob->id, $this->retryDelaySeconds($failedJob->attempts));
                $this->activityLog?->logJobWorkerEvent($failedJob, 'retry_scheduled', [
                    'delay_seconds' => $this->retryDelaySeconds($failedJob->attempts),
                ]);
            } elseif ($failedJob instanceof Job) {
                $this->alertService?->notifyJobFailedAfterMaxRetries($failedJob);
                $this->activityLog?->logJobWorkerEvent($failedJob, 'max_retries_reached');
            }

            return true;
        }

        if ($result->successful()) {
            $completedJob = Job::findById($this->db, $job->id);
            if ($completedJob instanceof Job) {
                $this->activityLog?->logJobWorkerEvent($completedJob, 'completed', [
                    'exit_code' => $result->exitCode,
                    'duration_ms' => $result->durationMs,
                ]);
            }
            $this->debugLogger?->info('Queue worker completed job successfully.', [
                'job_id' => $job->id,
                'site_id' => $job->siteId,
                'exit_code' => $result->exitCode,
                'duration_ms' => $result->durationMs,
            ]);
            return true;
        }

        $failedJob = Job::incrementAttempts($this->db, $job->id);
        $this->activityLog?->logJobWorkerEvent($failedJob ?? $job, 'failed_result', [
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'timed_out' => $result->timedOut,
        ]);
        $this->debugLogger?->error('Queue worker received failed execution result.', [
            'job_id' => $job->id,
            'site_id' => $job->siteId,
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'timed_out' => $result->timedOut,
        ]);

        if ($failedJob instanceof Job && $failedJob->attempts < $failedJob->maxAttempts) {
            Job::rescheduleForRetry($this->db, $failedJob->id, $this->retryDelaySeconds($failedJob->attempts));
            $this->activityLog?->logJobWorkerEvent($failedJob, 'retry_scheduled', [
                'delay_seconds' => $this->retryDelaySeconds($failedJob->attempts),
            ]);
        } elseif ($failedJob instanceof Job) {
            $this->alertService?->notifyJobFailedAfterMaxRetries($failedJob);
            $this->activityLog?->logJobWorkerEvent($failedJob, 'max_retries_reached');
        }

        return true;
    }

    private function retryDelaySeconds(int $attempts): int
    {
        return (int) pow(2, max(1, $attempts)) * 60;
    }
}
