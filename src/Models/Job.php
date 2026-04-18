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
    public const STRATEGY_AGENT_ONLY = 'agent_only';
    public const STRATEGY_AGENT_PREFERRED = 'agent_preferred';
    public const STRATEGY_SSH_ONLY = 'ssh_only';
    public const RUNNER_AGENT = 'agent';
    public const RUNNER_SSH = 'ssh';

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

    private const VALID_STRATEGIES = [
        self::STRATEGY_AGENT_ONLY,
        self::STRATEGY_AGENT_PREFERRED,
        self::STRATEGY_SSH_ONLY,
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
        public readonly string $executionStrategy,
        public readonly ?string $runnerType,
        public readonly ?string $claimedAt,
        public readonly ?string $dispatchReason,
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
        $executionStrategy = self::normalizeExecutionStrategy(
            $attributes['execution_strategy'] ?? null,
            $type
        );
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

        if (!in_array($executionStrategy, self::VALID_STRATEGIES, true)) {
            throw new InvalidArgumentException('execution_strategy is invalid.');
        }

        $id = trim((string) ($attributes['id'] ?? Uuid::v4()));

        $statement = $db->prepare(
            'INSERT INTO jobs (
                id, site_id, type, params, status, priority, attempts, max_attempts, scheduled_at, created_by, execution_strategy
             ) VALUES (
                :id, :site_id, :type, :params, :status, :priority, :attempts, :max_attempts, :scheduled_at, :created_by, :execution_strategy
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
            'execution_strategy' => $executionStrategy,
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
    public static function pendingForSite(PDO $db, string $siteId, ?array $strategies = null): array
    {
        if ($siteId === '') {
            throw new InvalidArgumentException('site_id is required.');
        }

        [$strategySql, $params] = self::strategyFilterSql($strategies);
        $statement = $db->prepare(
            'SELECT *
             FROM jobs
             WHERE site_id = :site_id
               AND status = :status
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())'
               . $strategySql .
            ' ORDER BY priority DESC, created_at ASC'
        );
        $statement->execute(array_merge([
            'site_id' => $siteId,
            'status' => self::STATUS_PENDING,
        ], $params));

        $jobs = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $jobs[] = self::fromRow($row);
            }
        }

        return $jobs;
    }

    public static function countPendingForSite(PDO $db, string $siteId, ?array $strategies = null): int
    {
        if ($siteId === '') {
            throw new InvalidArgumentException('site_id is required.');
        }

        [$strategySql, $params] = self::strategyFilterSql($strategies);
        $statement = $db->prepare(
            'SELECT COUNT(*)
             FROM jobs
             WHERE site_id = :site_id
               AND status = :status
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())'
               . $strategySql
        );
        $statement->execute(array_merge([
            'site_id' => $siteId,
            'status' => self::STATUS_PENDING,
        ], $params));

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

    public static function claimNextPendingForSsh(PDO $db): ?self
    {
        return self::claimPendingByQuery(
            $db,
            'WHERE status = \'pending\'
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())
               AND execution_strategy = \'' . self::STRATEGY_SSH_ONLY . '\'',
            [],
            self::RUNNER_SSH,
            'ssh_worker'
        );
    }

    public static function claimNextPendingForAgent(PDO $db, string $siteId): ?self
    {
        if ($siteId === '') {
            throw new InvalidArgumentException('site_id is required.');
        }

        return self::claimPendingByQuery(
            $db,
            'WHERE site_id = :site_id
               AND status = \'pending\'
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())
               AND execution_strategy IN (\'' . self::STRATEGY_AGENT_ONLY . '\', \'' . self::STRATEGY_AGENT_PREFERRED . '\')',
            ['site_id' => $siteId],
            self::RUNNER_AGENT,
            'agent_poll'
        );
    }

    public static function claimPendingByIdForAgent(PDO $db, string $id, string $dispatchReason): ?self
    {
        return self::claimPendingById($db, $id, self::RUNNER_AGENT, $dispatchReason);
    }

    public static function claimPendingByIdForSsh(PDO $db, string $id, string $dispatchReason): ?self
    {
        return self::claimPendingById($db, $id, self::RUNNER_SSH, $dispatchReason);
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
                 started_at = NULL,
                 runner_type = NULL,
                 claimed_at = NULL,
                 dispatch_reason = NULL
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
                 scheduled_at = DATE_ADD(NOW(), INTERVAL :delay_seconds SECOND),
                 runner_type = NULL,
                 claimed_at = NULL,
                 dispatch_reason = NULL
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
            'execution_strategy' => $this->executionStrategy,
            'runner_type' => $this->runnerType,
            'claimed_at' => $this->claimedAt,
            'dispatch_reason' => $this->dispatchReason,
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
            executionStrategy: self::normalizeExecutionStrategy($row['execution_strategy'] ?? null, (string) $row['type']),
            runnerType: self::nullableString($row['runner_type'] ?? null),
            claimedAt: self::nullableString($row['claimed_at'] ?? null),
            dispatchReason: self::nullableString($row['dispatch_reason'] ?? null),
            createdAt: (string) $row['created_at'],
        );
    }

    public static function defaultExecutionStrategyForType(string $type): string
    {
        return match ($type) {
            'cache.flush', 'plugin.update' => self::STRATEGY_AGENT_ONLY,
            default => self::STRATEGY_SSH_ONLY,
        };
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

    private static function normalizeExecutionStrategy(mixed $value, string $type): string
    {
        if (is_string($value) && in_array($value, self::VALID_STRATEGIES, true)) {
            return $value;
        }

        return self::defaultExecutionStrategyForType($type);
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private static function strategyFilterSql(?array $strategies): array
    {
        if ($strategies === null || $strategies === []) {
            return ['', []];
        }

        $filtered = array_values(array_filter(
            $strategies,
            static fn (mixed $strategy): bool => is_string($strategy) && in_array($strategy, self::VALID_STRATEGIES, true)
        ));

        if ($filtered === []) {
            return ['', []];
        }

        $placeholders = [];
        $params = [];
        foreach ($filtered as $index => $strategy) {
            $key = 'strategy_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $strategy;
        }

        return [' AND execution_strategy IN (' . implode(', ', $placeholders) . ')', $params];
    }

    /**
     * @param array<string, scalar|null> $params
     */
    private static function claimPendingByQuery(
        PDO $db,
        string $whereSql,
        array $params,
        string $runnerType,
        string $dispatchReason
    ): ?self {
        $db->beginTransaction();

        try {
            $statement = $db->prepare(
                'SELECT id
                 FROM jobs '
                 . $whereSql .
                ' ORDER BY priority DESC, created_at ASC
                  LIMIT 1
                  FOR UPDATE'
            );
            $statement->execute($params);
            $id = $statement->fetchColumn();

            if (!is_string($id) || $id === '') {
                $db->commit();
                return null;
            }

            $update = $db->prepare(
                'UPDATE jobs
                 SET status = :status,
                     runner_type = :runner_type,
                     claimed_at = NOW(),
                     dispatch_reason = :dispatch_reason,
                     started_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'status' => self::STATUS_RUNNING,
                'runner_type' => $runnerType,
                'dispatch_reason' => $dispatchReason,
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

    private static function claimPendingById(PDO $db, string $id, string $runnerType, string $dispatchReason): ?self
    {
        $db->beginTransaction();

        try {
            $statement = $db->prepare(
                'SELECT id
                 FROM jobs
                 WHERE id = :id
                   AND status = :status
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute([
                'id' => $id,
                'status' => self::STATUS_PENDING,
            ]);
            $claimedId = $statement->fetchColumn();

            if (!is_string($claimedId) || $claimedId === '') {
                $db->commit();
                return null;
            }

            $update = $db->prepare(
                'UPDATE jobs
                 SET status = :status,
                     runner_type = :runner_type,
                     claimed_at = NOW(),
                     dispatch_reason = :dispatch_reason,
                     started_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'status' => self::STATUS_RUNNING,
                'runner_type' => $runnerType,
                'dispatch_reason' => $dispatchReason,
                'id' => $claimedId,
            ]);

            $job = self::findById($db, $claimedId);
            $db->commit();

            return $job;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $exception;
        }
    }
}
