<?php

declare(strict_types=1);

namespace JetWP\Control\Jobs;

use InvalidArgumentException;
use JetWP\Control\Models\Job;
use PDO;

final class JobFactory
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(array $attributes): Job
    {
        return Job::create($this->db, $attributes);
    }

    public function retry(Job $job, string $createdBy = 'manual'): Job
    {
        if ($job->status !== Job::STATUS_FAILED) {
            throw new InvalidArgumentException('Only failed jobs can be retried.');
        }

        return Job::create($this->db, [
            'site_id' => $job->siteId,
            'type' => $job->type,
            'params' => $job->params ?? [],
            'status' => Job::STATUS_PENDING,
            'priority' => $job->priority,
            'attempts' => 0,
            'max_attempts' => $job->maxAttempts,
            'scheduled_at' => null,
            'created_by' => $createdBy,
        ]);
    }
}
