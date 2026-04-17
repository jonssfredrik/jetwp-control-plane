<?php

declare(strict_types=1);

namespace JetWP\Control\Models;

use InvalidArgumentException;
use JetWP\Control\Support\Uuid;
use PDO;

final class Job
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    private const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    private const FINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public function __construct(
        public readonly string $id,
        public readonly string $siteId,
        public readonly string $type,
        public readonly ?array $params,
        public readonly string $status,
        public readonly int $priority,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?string $scheduledAt,
        public readonly ?string $startedAt,
        public readonly ?string $completedAt,
        public readonly ?int $durationMs,
        public readonly ?string $output,
        public readonly ?string $errorOutput,
        public readonly string $createdBy,
        public readonly string $createdAt,
    ) {
    }

    public static function create(PDO $db, array $attributes): self
    {
        $siteId = trim((string) ($attributes['site_id'] ?? ''));
        $type = trim((string) ($attributes['type'] ?? ''));
        $status = trim((string) ($attributes['status'] ?? self::STATUS_PENDING));
        $priority = (int) ($attributes['priority'] ?? 5);
        $attempts = (int) ($attributes['attempts'] ?? 0);
        $maxAttempts = (int) ($attributes['max_attempts'] ?? 3);
        $scheduledAt = self::nullableString($attributes['scheduled_at'] ?? null);
        $createdBy = trim((string) ($attributes['created_by'] ?? 'manual'));
        $params = self::normalizeParams($attributes['params'] ?? null);

        if ($siteId === '' || $type === '') {
            throw new InvalidArgumentException('site_id and type are required.');
        }

        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException('Job status is invalid.');
        }

        if ($priority < 1 || $priority > 10) {
            throw new InvalidArgumentException('priority must be between 1 and 10.');
        }

        if ($attempts < 0 || $maxAttempts < 1) {
            throw new InvalidArgumentException('attempts and max_attempts are invalid.');
        }

        if ($createdBy === '') {
            throw new InvalidArgumentException('created_by is required.');
        }

        $id = trim((string) ($attributes['id'] ?? Uuid::v4()));

        $statement = $db->prepare(
            'INSERT INTO jobs (
                id, site_id, type, params, status, priority, attempts, max_attempts, scheduled_at, created_by
             ) VALUES (
                :id, :site_id, :type, :params, :status, :priority, :attempts, :max_attempts, :scheduled_at, :created_by
             )'
        );
        $statement->execute([
            'id' => $id,
            'site_id' => $siteId,
            'type' => $type,
            'params' => self::encodeParams($params),
            'status' => $status,
            'priority' => $priority,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'scheduled_at' => $scheduledAt,
            'created_by' => $createdBy,
        ]);

        return self::findById($db, $id);
    }

    public static function findById(PDO $db, string $id): ?self
    {
        $statement = $db->prepare('SELECT * FROM jobs WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * @return list<self>
     */
    public static function list(PDO $db, array $filters = [], int $limit = 100): array
    {
        $limit = max(1, min($limit, 250));
        $sql = 'SELECT * FROM jobs';
        $conditions = [];
        $params = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $conditions[] = 'type = :type';
            $params['type'] = $type;
        }

        $siteId = trim((string) ($filters['site_id'] ?? ''));
        if ($siteId !== '') {
            $conditions[] = 'site_id = :site_id';
            $params['site_id'] = $siteId;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;

        $statement = $db->prepare($sql);
        $statement->execute($params);

        $jobs = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $jobs[] = self::fromRow($row);
            }
        }

        return $jobs;
    }

    /**
     * @return list<self>
     */
    public static function pendingForSite(PDO $db, string $siteId): array
    {
        if ($siteId === '') {
            throw new InvalidArgumentException('site_id is required.');
        }

        $statement = $db->prepare(
            'SELECT *
             FROM jobs
             WHERE site_id = :site_id
               AND status = :status
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())
             ORDER BY priority DESC, created_at ASC'
        );
        $statement->execute([
            'site_id' => $siteId,
            'status' => self::STATUS_PENDING,
        ]);

        $jobs = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $jobs[] = self::fromRow($row);
            }
        }

        return $jobs;
    }

    public static function countPendingForSite(PDO $db, string $siteId): int
    {
        if ($siteId === '') {
            throw new InvalidArgumentException('site_id is required.');
        }

        $statement = $db->prepare(
            'SELECT COUNT(*)
             FROM jobs
             WHERE site_id = :site_id
               AND status = :status
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())'
        );
        $statement->execute([
            'site_id' => $siteId,
            'status' => self::STATUS_PENDING,
        ]);

        return (int) $statement->fetchColumn();
    }

    public static function findByIdForSite(PDO $db, string $id, string $siteId): ?self
    {
        if ($id === '' || $siteId === '') {
            throw new InvalidArgumentException('job id and site_id are required.');
        }

        $statement = $db->prepare(
            'SELECT *
             FROM jobs
             WHERE id = :id AND site_id = :site_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $id,
            'site_id' => $siteId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    public static function updateResult(
        PDO $db,
        string $id,
        string $siteId,
        string $status,
        ?string $output,
        ?int $durationMs,
        ?string $errorOutput = null
    ): void {
        if ($id === '' || $siteId === '') {
            throw new InvalidArgumentException('job id and site_id are required.');
        }

        if (!in_array($status, self::FINAL_STATUSES, true)) {
            throw new InvalidArgumentException('Job status is invalid.');
        }

        if ($durationMs !== null && $durationMs < 0) {
            throw new InvalidArgumentException('duration_ms must be zero or greater.');
        }

        $statement = $db->prepare(
            'UPDATE jobs
             SET status = :status,
                 output = :output,
                 error_output = :error_output,
                 duration_ms = :duration_ms,
                 completed_at = NOW()
             WHERE id = :id AND site_id = :site_id'
        );
        $statement->execute([
            'status' => $status,
            'output' => $output,
            'error_output' => $errorOutput,
            'duration_ms' => $durationMs,
            'id' => $id,
            'site_id' => $siteId,
        ]);
    }

    public static function cancel(PDO $db, string $id): ?self
    {
        $job = self::findById($db, $id);
        if (!$job instanceof self) {
            return null;
        }

        if ($job->status !== self::STATUS_PENDING) {
            throw new InvalidArgumentException('Only pending jobs can be cancelled.');
        }

        $statement = $db->prepare(
            'UPDATE jobs
             SET status = :status,
                 completed_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'status' => self::STATUS_CANCELLED,
            'id' => $id,
        ]);

        return self::findById($db, $id);
    }

    public static function markRunning(PDO $db, string $id): ?self
    {
        $statement = $db->prepare(
            'UPDATE jobs
             SET status = :status,
                 started_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'status' => self::STATUS_RUNNING,
            'id' => $id,
        ]);

        return self::findById($db, $id);
    }

    public static function claimNextPending(PDO $db): ?self
    {
        $db->beginTransaction();

        try {
            $statement = $db->query(
                'SELECT id
                 FROM jobs
                 WHERE status = \'pending\'
                   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                 ORDER BY priority DESC, created_at ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $id = $statement->fetchColumn();

            if (!is_string($id) || $id === '') {
                $db->commit();
                return null;
            }

            $update = $db->prepare(
                'UPDATE jobs
                 SET status = :status,
                     started_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'status' => self::STATUS_RUNNING,
                'id' => $id,
            ]);

            $job = self::findById($db, $id);
            $db->commit();

            return $job;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }

    public static function markCompleted(
        PDO $db,
        string $id,
        ?string $output,
        ?string $errorOutput,
        ?int $durationMs
    ): ?self {
        $statement = $db->prepare(
            'UPDATE jobs
             SET status = :status,
                 output = :output,
                 error_output = :error_output,
                 duration_ms = :duration_ms,
                 completed_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'status' => self::STATUS_COMPLETED,
            'output' => $output,
            'error_output' => $errorOutput,
            'duration_ms' => $durationMs,
            'id' => $id,
        ]);

        return self::findById($db, $id);
    }

    public static function markFailed(
        PDO $db,
        string $id,
        ?string $output,
        ?string $errorOutput,
        ?int $durationMs
    ): ?self {
        $statement = $db->prepare(
            'UPDATE jobs
             SET status = :status,
                 output = :output,
                 error_output = :error_output,
                 duration_ms = :duration_ms,
                 completed_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'status' => self::STATUS_FAILED,
            'output' => $output,
            'error_output' => $errorOutput,
            'duration_ms' => $durationMs,
            'id' => $id,
        ]);

        return self::findById($db, $id);
    }

    public static function restorePending(PDO $db, string $id): ?self
    {
        $statement = $db->prepare(
            'UPDATE jobs
             SET status = :status,
                 started_at = NULL
             WHERE id = :id'
        );
        $statement->execute([
            'status' => self::STATUS_PENDING,
            'id' => $id,
        ]);

        return self::findById($db, $id);
    }

    public static function incrementAttempts(PDO $db, string $id): ?self
    {
        $statement = $db->prepare(
            'UPDATE jobs
             SET attempts = attempts + 1
             WHERE id = :id'
        );
        $statement->execute(['id' => $id]);

        return self::findById($db, $id);
    }

    public static function rescheduleForRetry(PDO $db, string $id, int $delaySeconds): ?self
    {
        $statement = $db->prepare(
            'UPDATE jobs
             SET status = :status,
                 started_at = NULL,
                 completed_at = NULL,
                 scheduled_at = DATE_ADD(NOW(), INTERVAL :delay_seconds SECOND)
             WHERE id = :id'
        );
        $statement->bindValue('status', self::STATUS_PENDING);
        $statement->bindValue('delay_seconds', max(1, $delaySeconds), PDO::PARAM_INT);
        $statement->bindValue('id', $id);
        $statement->execute();

        return self::findById($db, $id);
    }

    public function toArray(bool $detail = false): array
    {
        $data = [
            'id' => $this->id,
            'site_id' => $this->siteId,
            'type' => $this->type,
            'params' => self::formatParams($this->params),
            'status' => $this->status,
            'priority' => $this->priority,
            'attempts' => $this->attempts,
            'max_attempts' => $this->maxAttempts,
            'scheduled_at' => $this->scheduledAt,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'duration_ms' => $this->durationMs,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
        ];

        if ($detail) {
            $data['output'] = $this->output;
            $data['error_output'] = $this->errorOutput;
        }

        return $data;
    }

    private static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            siteId: (string) $row['site_id'],
            type: (string) $row['type'],
            params: self::decodeParams($row['params'] ?? null),
            status: (string) $row['status'],
            priority: (int) $row['priority'],
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
            scheduledAt: self::nullableString($row['scheduled_at'] ?? null),
            startedAt: self::nullableString($row['started_at'] ?? null),
            completedAt: self::nullableString($row['completed_at'] ?? null),
            durationMs: isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
            output: self::nullableString($row['output'] ?? null),
            errorOutput: self::nullableString($row['error_output'] ?? null),
            createdBy: (string) $row['created_by'],
            createdAt: (string) $row['created_at'],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return $value === '' ? null : $value;
    }

    private static function normalizeParams(mixed $params): ?array
    {
        if ($params === null) {
            return null;
        }

        if (!is_array($params)) {
            throw new InvalidArgumentException('params must be an array or null.');
        }

        return $params;
    }

    private static function encodeParams(?array $params): ?string
    {
        if ($params === null) {
            return null;
        }

        if ($params === []) {
            return '{}';
        }

        $json = json_encode($params, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('params could not be encoded as JSON.');
        }

        return $json;
    }

    private static function decodeParams(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function formatParams(?array $params): array|object
    {
        if ($params === null || $params === []) {
            return (object) [];
        }

        return $params;
    }
}
