<?php

declare(strict_types=1);

namespace JetWP\Control\Services;

use JetWP\Control\Models\Workflow;
use JetWP\Control\Models\WorkflowEdge;
use JetWP\Control\Models\WorkflowNode;
use JetWP\Control\Workflows\WorkflowDefinitionValidator;
use PDO;

final class WorkflowService
{
    public function __construct(
        private readonly PDO $db,
        private readonly WorkflowDefinitionValidator $validator,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, ?int $userId): Workflow
    {
        $definition = $this->validator->validate($payload);

        $this->db->beginTransaction();

        try {
            $workflow = Workflow::create($this->db, [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'status' => $definition['status'],
                'created_by' => $userId,
            ]);

            WorkflowNode::replaceForWorkflow($this->db, $workflow->id, $definition['nodes']);
            WorkflowEdge::replaceForWorkflow($this->db, $workflow->id, $definition['edges']);

            $this->db->commit();

            return $workflow;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(string $workflowId, array $payload): ?Workflow
    {
        $definition = $this->validator->validate($payload);

        $this->db->beginTransaction();

        try {
            $workflow = Workflow::update($this->db, $workflowId, [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'status' => $definition['status'],
            ]);

            if (!$workflow instanceof Workflow) {
                $this->db->commit();
                return null;
            }

            WorkflowNode::replaceForWorkflow($this->db, $workflow->id, $definition['nodes']);
            WorkflowEdge::replaceForWorkflow($this->db, $workflow->id, $definition['edges']);

            $this->db->commit();

            return $workflow;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array{
     *   workflow: Workflow,
     *   nodes: list<WorkflowNode>,
     *   edges: list<WorkflowEdge>
     * }|null
     */
    public function loadGraph(string $workflowId): ?array
    {
        $workflow = Workflow::findById($this->db, $workflowId);
        if (!$workflow instanceof Workflow) {
            return null;
        }

        return [
            'workflow' => $workflow,
            'nodes' => WorkflowNode::forWorkflow($this->db, $workflowId),
            'edges' => WorkflowEdge::forWorkflow($this->db, $workflowId),
        ];
    }
}
