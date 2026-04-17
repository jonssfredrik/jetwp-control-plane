<?php

declare(strict_types=1);

namespace JetWP\Control\Services;

use InvalidArgumentException;
use JetWP\Control\Models\Job;
use JetWP\Control\Models\User;
use PDO;

final class ActivityLogService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function record(
        ?int $userId,
        ?string $siteId,
        string $action,
        array $details = [],
        ?string $ipAddress = null
    ): void {
        $action = trim($action);
        if ($action === '') {
            throw new InvalidArgumentException('Action is required.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO activity_log (user_id, site_id, action, details, ip_address)
             VALUES (:user_id, :site_id, :action, :details, :ip_address)'
        );
        $statement->execute([
            'user_id' => $userId,
            'site_id' => $siteId !== '' ? $siteId : null,
            'action' => $action,
            'details' => $this->encodeDetails($details),
            'ip_address' => $ipAddress ?? $this->requestIp(),
        ]);
    }

    public function logLoginSuccess(User $user): void
    {
        $this->record($user->id, null, 'auth.login.succeeded', [
            'username' => $user->username,
            'role' => $user->role,
        ]);
    }

    public function logLoginFailure(?int $userId, string $username, string $reason, array $details = []): void
    {
        $this->record($userId, null, 'auth.login.failed', array_merge($details, [
            'username' => $username,
            'reason' => $reason,
        ]));
    }

    public function logJobCreated(Job $job, ?User $actor, string $source, array $details = []): void
    {
        $this->record($actor?->id, $job->siteId, 'job.created', array_merge($details, [
            'job_id' => $job->id,
            'type' => $job->type,
            'status' => $job->status,
            'priority' => $job->priority,
            'created_by' => $job->createdBy,
            'source' => $source,
        ]));
    }

    public function logJobExecution(Job $job, string $outcome, array $details = []): void
    {
        $this->record(null, $job->siteId, 'job.execution.' . $outcome, array_merge($details, [
            'job_id' => $job->id,
            'type' => $job->type,
            'status' => $job->status,
            'attempts' => $job->attempts,
            'max_attempts' => $job->maxAttempts,
        ]));
    }

    public function logJobWorkerEvent(Job $job, string $event, array $details = []): void
    {
        $this->record(null, $job->siteId, 'job.worker.' . trim($event), array_merge($details, [
            'job_id' => $job->id,
            'type' => $job->type,
            'status' => $job->status,
            'attempts' => $job->attempts,
            'max_attempts' => $job->maxAttempts,
        ]));
    }

    public function logAlert(string $action, ?string $siteId, array $details = []): void
    {
        $this->record(null, $siteId, $action, $details);
    }

    public function logAgentRequest(?string $siteId, string $action, array $details = []): void
    {
        $this->record(null, $siteId, 'agent.' . trim($action), $details);
    }

    public function hasSiteActionSince(string $action, string $siteId, string $since): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM activity_log
             WHERE action = :action
               AND site_id = :site_id
               AND created_at >= :since'
        );
        $statement->execute([
            'action' => $action,
            'site_id' => $siteId,
            'since' => $since,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return list<array{id:int,user_id:?int,site_id:?string,action:string,details:array,ip_address:?string,created_at:string}>
     */
    public function recentForSite(string $siteId, int $limit = 50, ?string $actionPrefix = null): array
    {
        $siteId = trim($siteId);
        if ($siteId === '') {
            throw new InvalidArgumentException('site_id is required.');
        }

        $limit = max(1, min($limit, 200));
        $sql = 'SELECT id, user_id, site_id, action, details, ip_address, created_at
                FROM activity_log
                WHERE site_id = :site_id';
        $params = ['site_id' => $siteId];

        if ($actionPrefix !== null && trim($actionPrefix) !== '') {
            $sql .= ' AND action LIKE :action_prefix';
            $params['action_prefix'] = trim($actionPrefix) . '%';
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        $entries = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $entries[] = [
                'id' => (int) $row['id'],
                'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
                'site_id' => isset($row['site_id']) ? (string) $row['site_id'] : null,
                'action' => (string) $row['action'],
                'details' => $this->decodeDetails($row['details'] ?? null),
                'ip_address' => isset($row['ip_address']) ? (string) $row['ip_address'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{id:int,user_id:?int,site_id:?string,action:string,details:array,ip_address:?string,created_at:string}>
     */
    public function recentForJob(string $jobId, int $limit = 50): array
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            throw new InvalidArgumentException('job_id is required.');
        }

        $limit = max(1, min($limit, 200));
        $statement = $this->db->prepare(
            'SELECT id, user_id, site_id, action, details, ip_address, created_at
             FROM activity_log
             WHERE JSON_UNQUOTE(JSON_EXTRACT(details, \'$.job_id\')) = :job_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit
        );
        $statement->execute(['job_id' => $jobId]);

        $entries = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $entries[] = [
                'id' => (int) $row['id'],
                'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
                'site_id' => isset($row['site_id']) ? (string) $row['site_id'] : null,
                'action' => (string) $row['action'],
                'details' => $this->decodeDetails($row['details'] ?? null),
                'ip_address' => isset($row['ip_address']) ? (string) $row['ip_address'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $entries;
    }

    private function encodeDetails(array $details): ?string
    {
        if ($details === []) {
            return null;
        }

        $json = json_encode($details, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('Activity log details could not be encoded as JSON.');
        }

        return $json;
    }

    private function decodeDetails(mixed $details): array
    {
        if (!is_string($details) || trim($details) === '') {
            return [];
        }

        $decoded = json_decode($details, true);
        if (!is_array($decoded)) {
            return ['raw' => $details];
        }

        return $decoded;
    }

    private function requestIp(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['HTTP_CLIENT_IP'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $ip = trim(explode(',', $candidate)[0]);
            if ($ip !== '') {
                return $ip;
            }
        }

        return null;
    }
}
