<?php

declare(strict_types=1);

namespace JetWP\Control\Models;

use InvalidArgumentException;
use JetWP\Control\Support\Uuid;
use PDO;

final class WorkflowEdge
{
    public function __construct(
        public readonly string $id,
        public readonly string $workflowId,
        public readonly string $fromNodeKey,
        public readonly string $toNodeKey,
        public readonly string $edgeType,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $edges
     */
    public static function replaceForWorkflow(PDO $db, string $workflowId, array $edges): void
    {
        if ($workflowId === '') {
            throw new InvalidArgumentException('workflow_id is required.');
        }

        $delete = $db->prepare('DELETE FROM workflow_edges WHERE workflow_id = :workflow_id');
        $delete->execute(['workflow_id' => $workflowId]);

        if ($edges === []) {
            return;
        }

        $statement = $db->prepare(
            'INSERT INTO workflow_edges (
                id, workflow_id, from_node_key, to_node_key, edge_type
             ) VALUES (
                :id, :workflow_id, :from_node_key, :to_node_key, :edge_type
             )'
        );

        foreach ($edges as $edge) {
            $statement->execute([
                'id' => trim((string) ($edge['id'] ?? Uuid::v4())),
                'workflow_id' => $workflowId,
                'from_node_key' => trim((string) ($edge['from_node_key'] ?? '')),
                'to_node_key' => trim((string) ($edge['to_node_key'] ?? '')),
                'edge_type' => trim((string) ($edge['edge_type'] ?? 'next')),
            ]);
        }
    }

    /**
     * @return list<self>
     */
    public static function forWorkflow(PDO $db, string $workflowId): array
    {
        $statement = $db->prepare('SELECT * FROM workflow_edges WHERE workflow_id = :workflow_id ORDER BY created_at ASC');
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
            fromNodeKey: (string) $row['from_node_key'],
            toNodeKey: (string) $row['to_node_key'],
            edgeType: (string) $row['edge_type'],
            createdAt: (string) $row['created_at'],
        );
    }
}
