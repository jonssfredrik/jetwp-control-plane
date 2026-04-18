<?php

declare(strict_types=1);

namespace JetWP\Control\Workflows;

use JetWP\Control\Jobs\JobValidationException;

final class WorkflowDefinitionValidator
{
    /**
     * @param array<string, mixed> $payload
     * @return array{name:string,description:?string,status:string,nodes:list<array<string, mixed>>,edges:list<array<string, mixed>>}
     */
    public function validate(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $description = $this->nullableString($payload['description'] ?? null);
        $status = trim((string) ($payload['status'] ?? 'draft'));
        $nodes = is_array($payload['nodes'] ?? null) ? array_values($payload['nodes']) : [];
        $edges = is_array($payload['edges'] ?? null) ? array_values($payload['edges']) : [];

        $errors = [];

        if ($name === '') {
            $errors['name'][] = 'Workflow name is required.';
        }

        if (!in_array($status, ['draft', 'active', 'archived'], true)) {
            $errors['status'][] = 'Workflow status is invalid.';
        }

        if ($nodes === []) {
            $errors['nodes'][] = 'Workflow must contain at least one node.';
        }

        if ($errors !== []) {
            throw new JobValidationException($errors);
        }

        $normalizedNodes = $this->validateNodes($nodes);
        $normalizedEdges = $this->validateEdges($edges, $normalizedNodes);
        $this->validateGraph($normalizedNodes, $normalizedEdges);

        return [
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'nodes' => $normalizedNodes,
            'edges' => $normalizedEdges,
        ];
    }

    /**
     * @param list<mixed> $nodes
     * @return list<array<string, mixed>>
     */
    private function validateNodes(array $nodes): array
    {
        $errors = [];
        $normalized = [];
        $nodeKeys = [];

        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                $errors['nodes'][] = sprintf('Node %d must be an object.', $index + 1);
                continue;
            }

            $nodeKey = trim((string) ($node['node_key'] ?? ''));
            $type = trim((string) ($node['type'] ?? ''));
            $label = trim((string) ($node['label'] ?? ''));
            $positionX = (int) ($node['position_x'] ?? 0);
            $positionY = (int) ($node['position_y'] ?? 0);
            $config = $this->normalizeConfig($node['config'] ?? null);

            if ($nodeKey === '') {
                $errors['nodes'][] = sprintf('Node %d is missing node_key.', $index + 1);
                continue;
            }

            if (isset($nodeKeys[$nodeKey])) {
                $errors['nodes'][] = sprintf('Node key %s is duplicated.', $nodeKey);
                continue;
            }
            $nodeKeys[$nodeKey] = true;

            if (!WorkflowCatalog::hasNodeType($type)) {
                $errors['nodes'][] = sprintf('Node %s has unsupported type %s.', $nodeKey, $type);
                continue;
            }

            if ($label === '') {
                $label = WorkflowCatalog::definitionFor($type)['default_label'] ?? $type;
            }

            $configErrors = $this->validateNodeConfig($type, $config, $nodeKey);
            foreach ($configErrors as $message) {
                $errors['nodes'][] = $message;
            }

            $normalized[] = [
                'node_key' => $nodeKey,
                'type' => $type,
                'label' => $label,
                'config' => $config,
                'position_x' => $positionX,
                'position_y' => $positionY,
            ];
        }

        if ($errors !== []) {
            throw new JobValidationException($errors);
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $edges
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function validateEdges(array $edges, array $nodes): array
    {
        $errors = [];
        $nodeMap = [];
        foreach ($nodes as $node) {
            $nodeMap[(string) $node['node_key']] = (string) $node['type'];
        }

        $normalized = [];
        $edgeUnique = [];

        foreach ($edges as $index => $edge) {
            if (!is_array($edge)) {
                $errors['edges'][] = sprintf('Edge %d must be an object.', $index + 1);
                continue;
            }

            $from = trim((string) ($edge['from_node_key'] ?? ''));
            $to = trim((string) ($edge['to_node_key'] ?? ''));
            $type = trim((string) ($edge['edge_type'] ?? 'next'));

            if ($from === '' || $to === '') {
                $errors['edges'][] = sprintf('Edge %d must include from_node_key and to_node_key.', $index + 1);
                continue;
            }

            if (!isset($nodeMap[$from]) || !isset($nodeMap[$to])) {
                $errors['edges'][] = sprintf('Edge %s -> %s references an unknown node.', $from, $to);
                continue;
            }

            if (!in_array($type, ['next', 'true', 'false'], true)) {
                $errors['edges'][] = sprintf('Edge %s -> %s has invalid edge_type %s.', $from, $to, $type);
                continue;
            }

            $uniqueKey = $from . '|' . $type;
            if (isset($edgeUnique[$uniqueKey])) {
                $errors['edges'][] = sprintf('Node %s has multiple %s edges.', $from, $type);
                continue;
            }
            $edgeUnique[$uniqueKey] = true;

            $normalized[] = [
                'from_node_key' => $from,
                'to_node_key' => $to,
                'edge_type' => $type,
            ];
        }

        if ($errors !== []) {
            throw new JobValidationException($errors);
        }

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $edges
     */
    private function validateGraph(array $nodes, array $edges): void
    {
        $errors = [];
        $nodeMap = [];
        $startNodes = [];
        $adjacency = [];
        $edgeTypes = [];

        foreach ($nodes as $node) {
            $nodeKey = (string) $node['node_key'];
            $type = (string) $node['type'];
            $nodeMap[$nodeKey] = $type;
            $adjacency[$nodeKey] = [];
            $edgeTypes[$nodeKey] = [];

            if ($type === 'start') {
                $startNodes[] = $nodeKey;
            }
        }

        if (count($startNodes) !== 1) {
            $errors['graph'][] = 'Workflow must have exactly one start node.';
        }

        $endCount = count(array_filter($nodes, static fn (array $node): bool => $node['type'] === 'end'));
        if ($endCount < 1) {
            $errors['graph'][] = 'Workflow must contain at least one end node.';
        }

        foreach ($edges as $edge) {
            $from = (string) $edge['from_node_key'];
            $to = (string) $edge['to_node_key'];
            $type = (string) $edge['edge_type'];
            $adjacency[$from][] = $to;
            $edgeTypes[$from][] = $type;
        }

        foreach ($nodeMap as $nodeKey => $type) {
            $kind = WorkflowCatalog::kindFor($type);
            $types = $edgeTypes[$nodeKey];

            if ($kind === 'start' || $kind === 'action') {
                if ($type !== 'end' && $types !== ['next']) {
                    $errors['graph'][] = sprintf('Node %s must have exactly one next edge.', $nodeKey);
                }
            }

            if ($kind === 'condition') {
                sort($types);
                if ($types !== ['false', 'true']) {
                    $errors['graph'][] = sprintf('Condition node %s must have exactly one true edge and one false edge.', $nodeKey);
                }
            }

            if ($kind === 'end' && $types !== []) {
                $errors['graph'][] = sprintf('End node %s must not have outgoing edges.', $nodeKey);
            }
        }

        if ($errors !== []) {
            throw new JobValidationException($errors);
        }

        $startNode = $startNodes[0] ?? null;
        if ($startNode === null) {
            return;
        }

        $visited = [];
        $this->depthFirstVisit($startNode, $adjacency, $visited, []);

        foreach (array_keys($nodeMap) as $nodeKey) {
            if (!isset($visited[$nodeKey])) {
                $errors['graph'][] = sprintf('Node %s is not reachable from the start node.', $nodeKey);
            }
        }

        if ($errors !== []) {
            throw new JobValidationException($errors);
        }
    }

    /**
     * @param array<string, list<string>> $adjacency
     * @param array<string, bool> $visited
     * @param array<string, bool> $stack
     */
    private function depthFirstVisit(string $nodeKey, array $adjacency, array &$visited, array $stack): void
    {
        if (isset($stack[$nodeKey])) {
            throw new JobValidationException([
                'graph' => [sprintf('Workflow graph contains a loop at node %s.', $nodeKey)],
            ]);
        }

        if (isset($visited[$nodeKey])) {
            return;
        }

        $visited[$nodeKey] = true;
        $stack[$nodeKey] = true;

        foreach ($adjacency[$nodeKey] ?? [] as $toNodeKey) {
            $this->depthFirstVisit($toNodeKey, $adjacency, $visited, $stack);
        }
    }

    /**
     * @return list<string>
     */
    private function validateNodeConfig(string $type, array $config, string $nodeKey): array
    {
        $errors = [];

        if ($type === 'plugin.update.selected') {
            if (($config['plugins'] ?? []) === []) {
                $errors[] = sprintf('Node %s requires at least one plugin slug.', $nodeKey);
            }
        }

        if ($type === 'plugin.update.all_except') {
            if (($config['exclude_plugins'] ?? []) === []) {
                $errors[] = sprintf('Node %s requires at least one excluded plugin slug.', $nodeKey);
            }
        }

        if ($type === 'theme.update.selected') {
            if (($config['themes'] ?? []) === []) {
                $errors[] = sprintf('Node %s requires at least one theme slug.', $nodeKey);
            }
        }

        if ($type === 'theme.update.all_except') {
            if (($config['exclude_themes'] ?? []) === []) {
                $errors[] = sprintf('Node %s requires at least one excluded theme slug.', $nodeKey);
            }
        }

        if ($type === 'condition.plugin_updates_available') {
            $mode = (string) ($config['mode'] ?? '');
            if (!in_array($mode, ['selected', 'all_with_updates', 'all_except'], true)) {
                $errors[] = sprintf('Node %s has invalid plugin condition mode.', $nodeKey);
            }

            if ($mode === 'selected' && ($config['plugins'] ?? []) === []) {
                $errors[] = sprintf('Node %s requires plugin slugs for selected mode.', $nodeKey);
            }

            if ($mode === 'all_except' && ($config['exclude_plugins'] ?? []) === []) {
                $errors[] = sprintf('Node %s requires excluded plugin slugs for all_except mode.', $nodeKey);
            }
        }

        if ($type === 'condition.theme_updates_available') {
            $mode = (string) ($config['mode'] ?? '');
            if (!in_array($mode, ['selected', 'all_with_updates', 'all_except'], true)) {
                $errors[] = sprintf('Node %s has invalid theme condition mode.', $nodeKey);
            }

            if ($mode === 'selected' && ($config['themes'] ?? []) === []) {
                $errors[] = sprintf('Node %s requires theme slugs for selected mode.', $nodeKey);
            }

            if ($mode === 'all_except' && ($config['exclude_themes'] ?? []) === []) {
                $errors[] = sprintf('Node %s requires excluded theme slugs for all_except mode.', $nodeKey);
            }
        }

        return $errors;
    }

    private function normalizeConfig(mixed $config): array
    {
        if (!is_array($config) || array_is_list($config)) {
            return [];
        }

        $normalized = $config;

        foreach (['plugins', 'exclude_plugins', 'themes', 'exclude_themes'] as $listKey) {
            if (!array_key_exists($listKey, $normalized)) {
                continue;
            }

            $value = $normalized[$listKey];
            if (!is_array($value)) {
                $normalized[$listKey] = [];
                continue;
            }

            $items = [];
            foreach ($value as $item) {
                if (!is_string($item)) {
                    continue;
                }

                $trimmed = trim($item);
                if ($trimmed === '') {
                    continue;
                }
                $items[$trimmed] = $trimmed;
            }

            $normalized[$listKey] = array_values($items);
        }

        if (isset($normalized['version']) && !is_string($normalized['version'])) {
            $normalized['version'] = null;
        }

        if (isset($normalized['mode']) && !is_string($normalized['mode'])) {
            $normalized['mode'] = '';
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
