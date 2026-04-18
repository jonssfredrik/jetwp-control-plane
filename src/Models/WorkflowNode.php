<?php

declare(strict_types=1);

namespace JetWP\Control\Models;

use InvalidArgumentException;
use JetWP\Control\Support\Uuid;
use PDO;

final class WorkflowNode
{
    public function __construct(
        public readonly string $id,
        public readonly string $workflowId,
        public readonly string $nodeKey,
        public readonly string $type,
        public readonly string $label,
        public readonly ?array $config,
        public readonly int $positionX,
        public readonly int $positionY,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    public static function replaceForWorkflow(PDO $db, string $workflowId, array $nodes): void
    {
        if ($workflowId === '') {
            throw new InvalidArgumentException('workflow_id is required.');
        }

        $delete = $db->prepare('DELETE FROM workflow_nodes WHERE workflow_id = :workflow_id');
        $delete->execute(['workflow_id' => $workflowId]);

        if ($nodes === []) {
            return;
        }

        $statement = $db->prepare(
            'INSERT INTO workflow_nodes (
                id, workflow_id, node_key, type, label, config_json, position_x, position_y
             ) VALUES (
                :id, :workflow_id, :node_key, :type, :label, :config_json, :position_x, :position_y
             )'
        );

        foreach ($nodes as $node) {
            $config = self::normalizeConfig($node['config'] ?? null);
            $statement->execute([
                'id' => trim((string) ($node['id'] ?? Uuid::v4())),
                'workflow_id' => $workflowId,
                'node_key' => trim((string) ($node['node_key'] ?? '')),
                'type' => trim((string) ($node['type'] ?? '')),
                'label' => trim((string) ($node['label'] ?? '')),
                'config_json' => self::encodeConfig($config),
                'position_x' => (int) ($node['position_x'] ?? 0),
                'position_y' => (int) ($node['position_y'] ?? 0),
            ]);
        }
    }

    /**
     * @return list<self>
     */
    public static function forWorkflow(PDO $db, string $workflowId): array
    {
        $statement = $db->prepare('SELECT * FROM workflow_nodes WHERE workflow_id = :workflow_id ORDER BY created_at ASC');
        $statement->execute(['workflow_id' => $workflowId]);
        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $items[] = self::fromRow($row);
            }
        }

        return $items;
    }

    private static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            workflowId: (string) $row['workflow_id'],
            nodeKey: (string) $row['node_key'],
            type: (string) $row['type'],
            label: (string) $row['label'],
            config: self::decodeConfig($row['config_json'] ?? null),
            positionX: (int) $row['position_x'],
            positionY: (int) $row['position_y'],
            createdAt: (string) $row['created_at'],
        );
    }

    private static function normalizeConfig(mixed $config): ?array
    {
        if ($config === null) {
            return null;
        }

        if (!is_array($config) || array_is_list($config)) {
            throw new InvalidArgumentException('Workflow node config must be an object or null.');
        }

        return $config;
    }

    private static function encodeConfig(?array $config): ?string
    {
        if ($config === null) {
            return null;
        }

        if ($config === []) {
            return '{}';
        }

        $json = json_encode($config, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('Workflow node config could not be encoded.');
        }

        return $json;
    }

    private static function decodeConfig(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }
}
