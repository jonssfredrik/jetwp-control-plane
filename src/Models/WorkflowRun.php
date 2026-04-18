<?php

declare(strict_types=1);

namespace JetWP\Control\Models;

use InvalidArgumentException;
use JetWP\Control\Support\Uuid;
use PDO;

final class WorkflowRun
{
    public function __construct(
        public readonly string $id,
        public readonly string $workflowId,
        public readonly string $siteId,
        public readonly string $status,
        public readonly ?string $currentNodeKey,
        public readonly ?array $context,
        public readonly ?string $startedAt,
        public readonly ?string $completedAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    public static function create(PDO $db, array $attributes): self
    {
        $id = trim((string) ($attributes['id'] ?? Uuid::v4()));
        $workflowId = trim((string) ($attributes['workflow_id'] ?? ''));
        $siteId = trim((string) ($attributes['site_id'] ?? ''));
        $status = trim((string) ($attributes['status'] ?? 'pending'));
        $currentNodeKey = self::nullableString($attributes['current_node_key'] ?? null);
        $context = self::normalizeContext($attributes['context'] ?? null);

        if ($workflowId === '' || $siteId === '') {
            throw new InvalidArgumentException('workflow_id and site_id are required.');
        }

        if (!in_array($status, ['pending', 'running', 'completed', 'failed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Workflow run status is invalid.');
        }

        $statement = $db->prepare(
            'INSERT INTO workflow_runs (id, workflow_id, site_id, status, current_node_key, context_json, started_at)
             VALUES (:id, :workflow_id, :site_id, :status, :current_node_key, :context_json, NOW())'
        );
        $statement->execute([
            'id' => $id,
            'workflow_id' => $workflowId,
            'site_id' => $siteId,
            'status' => $status,
            'current_node_key' => $currentNodeKey,
            'context_json' => self::encodeContext($context),
        ]);

        return self::findById($db, $id);
    }

    public static function findById(PDO $db, string $id): ?self
    {
        $statement = $db->prepare('SELECT * FROM workflow_runs WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * @return list<self>
     */
    public static function recentForWorkflow(PDO $db, string $workflowId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $statement = $db->prepare(
            'SELECT * FROM workflow_runs
             WHERE workflow_id = :workflow_id
             ORDER BY created_at DESC
             LIMIT ' . $limit
        );
        $statement->execute(['workflow_id' => $workflowId]);
        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $items[] = self::fromRow($row);
            }
        }

        return $items;
    }

    public static function updateState(PDO $db, string $id, string $status, ?string $currentNodeKey, ?array $context = null): ?self
    {
        if (!in_array($status, ['pending', 'running', 'completed', 'failed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Workflow run status is invalid.');
        }

        $statement = $db->prepare(
            'UPDATE workflow_runs
             SET status = :status,
                 current_node_key = :current_node_key,
                 context_json = COALESCE(:context_json, context_json)
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'status' => $status,
            'current_node_key' => self::nullableString($currentNodeKey),
            'context_json' => $context !== null ? self::encodeContext($context) : null,
        ]);

        return self::findById($db, $id);
    }

    public static function finish(PDO $db, string $id, string $status, ?array $context = null): ?self
    {
        if (!in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Workflow run final status is invalid.');
        }

        $statement = $db->prepare(
            'UPDATE workflow_runs
             SET status = :status,
                 current_node_key = NULL,
                 context_json = COALESCE(:context_json, context_json),
                 completed_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'status' => $status,
            'context_json' => $context !== null ? self::encodeContext($context) : null,
        ]);

        return self::findById($db, $id);
    }

    private static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            workflowId: (string) $row['workflow_id'],
            siteId: (string) $row['site_id'],
            status: (string) $row['status'],
            currentNodeKey: self::nullableString($row['current_node_key'] ?? null),
            context: self::decodeContext($row['context_json'] ?? null),
            startedAt: self::nullableString($row['started_at'] ?? null),
            completedAt: self::nullableString($row['completed_at'] ?? null),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    private static function normalizeContext(mixed $context): ?array
    {
        if ($context === null) {
            return null;
        }

        if (!is_array($context) || array_is_list($context)) {
            throw new InvalidArgumentException('Workflow run context must be an object or null.');
        }

        return $context;
    }

    private static function encodeContext(?array $context): ?string
    {
        if ($context === null) {
            return null;
        }

        $json = json_encode($context, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('Workflow run context could not be encoded.');
        }

        return $json;
    }

    private static function decodeContext(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
