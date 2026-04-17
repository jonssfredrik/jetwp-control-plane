<?php

declare(strict_types=1);

namespace JetWP\Control\Queue;

use JetWP\Control\Models\Job;
use JetWP\Control\Runner\JobExecutor;
use JetWP\Control\Services\AlertService;
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
        $job = Job::claimNextPending($this->db);
        if (!$job instanceof Job) {
            return false;
        }

        if (!$this->concurrencyManager->hasCapacityForSite($job->siteId)) {
            Job::restorePending($this->db, $job->id);
            return false;
        }

        try {
            $result = $this->executor->executeClaimed(
                $job,
                false,
                $this->defaultTimeoutSeconds
            );
        } catch (Throwable $exception) {
            $failedJob = Job::incrementAttempts($this->db, $job->id);
            if ($failedJob instanceof Job && $failedJob->attempts < $failedJob->maxAttempts) {
                Job::rescheduleForRetry($this->db, $failedJob->id, $this->retryDelaySeconds($failedJob->attempts));
            } elseif ($failedJob instanceof Job) {
                $this->alertService?->notifyJobFailedAfterMaxRetries($failedJob);
            }

            return true;
        }

        if ($result->successful()) {
            return true;
        }

        $failedJob = Job::incrementAttempts($this->db, $job->id);
        if ($failedJob instanceof Job && $failedJob->attempts < $failedJob->maxAttempts) {
            Job::rescheduleForRetry($this->db, $failedJob->id, $this->retryDelaySeconds($failedJob->attempts));
        } elseif ($failedJob instanceof Job) {
            $this->alertService?->notifyJobFailedAfterMaxRetries($failedJob);
        }

        return true;
    }

    private function retryDelaySeconds(int $attempts): int
    {
        return (int) pow(2, max(1, $attempts)) * 60;
    }
}
