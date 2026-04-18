<?php

declare(strict_types=1);

namespace JetWP\Control\Models;

use InvalidArgumentException;
use JetWP\Control\Support\Uuid;
use PDO;

final class WorkflowRunStep
{
    public function __construct(
        public readonly string $id,
        public readonly string $workflowRunId,
        public readonly string $nodeKey,
        public readonly string $nodeType,
        public readonly string $status,
        public readonly ?string $jobId,
        public readonly ?array $input,
        public readonly ?array $output,
        public readonly ?string $errorOutput,
        public readonly ?string $startedAt,
        public readonly ?string $completedAt,
        public readonly string $createdAt,
    ) {
    }

    public static function create(PDO $db, array $attributes): self
    {
        $id = trim((string) ($attributes['id'] ?? Uuid::v4()));
        $workflowRunId = trim((string) ($attributes['workflow_run_id'] ?? ''));
        $nodeKey = trim((string) ($attributes['node_key'] ?? ''));
        $nodeType = trim((string) ($attributes['node_type'] ?? ''));
        $status = trim((string) ($attributes['status'] ?? 'completed'));
        $jobId = self::nullableString($attributes['job_id'] ?? null);
        $input = self::normalizePayload($attributes['input'] ?? null);
        $output = self::normalizePayload($attributes['output'] ?? null);
        $errorOutput = self::nullableString($attributes['error_output'] ?? null);

        if ($workflowRunId === '' || $nodeKey === '' || $nodeType === '') {
            throw new InvalidArgumentException('workflow_run_id, node_key and node_type are required.');
        }

        if (!in_array($status, ['running', 'completed', 'failed', 'skipped'], true)) {
            throw new InvalidArgumentException('Workflow run step status is invalid.');
        }

        $statement = $db->prepare(
            'INSERT INTO workflow_run_steps (
                id, workflow_run_id, node_key, node_type, status, job_id, input_json, output_json, error_output, started_at, completed_at
             ) VALUES (
                :id, :workflow_run_id, :node_key, :node_type, :status, :job_id, :input_json, :output_json, :error_output, NOW(), :completed_at
             )'
        );
        $statement->execute([
            'id' => $id,
            'workflow_run_id' => $workflowRunId,
            'node_key' => $nodeKey,
            'node_type' => $nodeType,
            'status' => $status,
            'job_id' => $jobId,
            'input_json' => self::encodePayload($input),
            'output_json' => self::encodePayload($output),
            'error_output' => $errorOutput,
            'completed_at' => $status === 'running' ? null : date('Y-m-d H:i:s'),
        ]);

        return self::findById($db, $id);
    }

    public static function findById(PDO $db, string $id): ?self
    {
        $statement = $db->prepare('SELECT * FROM workflow_run_steps WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * @return list<self>
     */
    public static function forRun(PDO $db, string $workflowRunId): array
    {
        $statement = $db->prepare(
            'SELECT * FROM workflow_run_steps
             WHERE workflow_run_id = :workflow_run_id
             ORDER BY created_at ASC'
        );
        $statement->execute(['workflow_run_id' => $workflowRunId]);
        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $items[] = self::fromRow($row);
            }
        }

        return $items;
    }

    public static function latestForRun(PDO $db, string $workflowRunId): ?self
    {
        $statement = $db->prepare(
            'SELECT * FROM workflow_run_steps
             WHERE workflow_run_id = :workflow_run_id
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $statement->execute(['workflow_run_id' => $workflowRunId]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    private static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            workflowRunId: (string) $row['workflow_run_id'],
            nodeKey: (string) $row['node_key'],
            nodeType: (string) $row['node_type'],
            status: (string) $row['status'],
            jobId: self::nullableString($row['job_id'] ?? null),
            input: self::decodePayload($row['input_json'] ?? null),
            output: self::decodePayload($row['output_json'] ?? null),
            errorOutput: self::nullableString($row['error_output'] ?? null),
            startedAt: self::nullableString($row['started_at'] ?? null),
            completedAt: self::nullableString($row['completed_at'] ?? null),
            createdAt: (string) $row['created_at'],
        );
    }

    private static function normalizePayload(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Workflow run step payload must be an object or null.');
        }

        return $value;
    }

    private static function encodePayload(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('Workflow run step payload could not be encoded.');
        }

        return $json;
    }

    private static function decodePayload(mixed $value): ?array
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
