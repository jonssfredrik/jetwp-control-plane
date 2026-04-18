<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
/** @var JetWP\Control\Models\Workflow|null $workflow */
/** @var array<int, JetWP\Control\Models\WorkflowNode> $nodes */
/** @var array<int, JetWP\Control\Models\WorkflowEdge> $edges */
/** @var array<string, array<string, mixed>> $nodeCatalog */
/** @var list<JetWP\Control\Models\Site> $sites */
/** @var list<JetWP\Control\Models\WorkflowRun> $recentRuns */
/** @var array{type: string, message: string}|null $flash */

$isEditing = $workflow instanceof \JetWP\Control\Models\Workflow;
$pageTitle = $appName . ' · ' . ($isEditing ? $workflow->name : 'New Workflow');
$pageEyebrow = 'Workflow Builder';
$pageHeading = $isEditing ? $workflow->name : 'New Workflow';
$pageLead = 'Build a visual DAG of typed nodes, then run it for one site at a time.';
$activeNav = 'workflows';
$pageHeaderAside = '<a class="btn ghost" href="/dashboard/workflows"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>Back to Workflows</a>';

$initialNodes = array_map(static function (\JetWP\Control\Models\WorkflowNode $node): array {
    return [
        'id' => $node->id,
        'node_key' => $node->nodeKey,
        'type' => $node->type,
        'label' => $node->label,
        'config' => $node->config ?? (object) [],
        'position_x' => $node->positionX,
        'position_y' => $node->positionY,
    ];
}, $nodes);

$initialEdges = array_map(static function (\JetWP\Control\Models\WorkflowEdge $edge): array {
    return [
        'id' => $edge->id,
        'from_node_key' => $edge->fromNodeKey,
        'to_node_key' => $edge->toNodeKey,
        'edge_type' => $edge->edgeType,
    ];
}, $edges);

if ($initialNodes === []) {
    $initialNodes = [
        [
            'id' => '',
            'node_key' => 'start',
            'type' => 'start',
            'label' => 'Start',
            'config' => (object) [],
            'position_x' => 80,
            'position_y' => 120,
        ],
        [
            'id' => '',
            'node_key' => 'end',
            'type' => 'end',
            'label' => 'End',
            'config' => (object) [],
            'position_x' => 420,
            'position_y' => 120,
        ],
    ];
    $initialEdges = [
        [
            'id' => '',
            'from_node_key' => 'start',
            'to_node_key' => 'end',
            'edge_type' => 'next',
        ],
    ];
}

require __DIR__ . '/../_chrome.php';
?>

<?php if ($flash !== null): ?>
    <div class="flash <?= htmlspecialchars($flash['type']) ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </div>
<?php endif; ?>

<section class="stack-lg">
    <div class="grid" style="grid-template-columns: 320px 1fr 340px; align-items:start;">
        <aside class="panel stack">
            <div class="label">Workflow</div>
            <label class="field" for="wf-name">Name</label>
            <input id="wf-name" type="text" value="<?= htmlspecialchars($workflow?->name ?? '') ?>" placeholder="Nightly plugin maintenance">

            <label class="field" for="wf-description">Description</label>
            <textarea id="wf-description" style="min-height:120px;"><?= htmlspecialchars($workflow?->description ?? '') ?></textarea>

            <label class="field" for="wf-status">Status</label>
            <select id="wf-status">
                <?php foreach (['draft', 'active', 'archived'] as $status): ?>
                    <option value="<?= htmlspecialchars($status) ?>" <?= ($workflow?->status ?? 'draft') === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="divider"></div>
            <div class="label">Palette</div>
            <div class="stack" id="palette-list"></div>
        </aside>

        <div class="panel" style="overflow:hidden;">
            <div class="row" style="justify-content: space-between; margin-bottom: 14px;">
                <div class="label">Canvas</div>
                <div class="row">
                    <button type="button" class="ghost" id="delete-node-btn">Delete Selected</button>
                    <button type="button" class="ghost" id="save-workflow-btn">Save Workflow</button>
                </div>
            </div>
            <div id="workflow-canvas-wrap" style="position:relative; overflow:auto; height:720px; border-radius:16px; border:1px solid var(--line); background:rgba(5,7,13,0.55);">
                <svg id="workflow-edge-layer" width="2200" height="1400" style="position:absolute; inset:0; pointer-events:none;"></svg>
                <div id="workflow-canvas" style="position:relative; width:2200px; height:1400px;"></div>
            </div>
        </div>

        <aside class="panel stack">
            <div class="label">Selected Node</div>
            <div id="selected-node-empty" class="muted">Select a node to edit its settings.</div>
            <div id="selected-node-settings" style="display:none;"></div>

            <div class="divider"></div>
            <div class="label">Run Workflow</div>
            <label class="field" for="workflow-run-site">Site</label>
            <select id="workflow-run-site">
                <option value="">Select site</option>
                <?php foreach ($sites as $site): ?>
                    <option value="<?= htmlspecialchars($site->id) ?>"><?= htmlspecialchars($site->label . ' (' . $site->url . ')') ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="run-workflow-btn">Run Now</button>
            <p class="muted">Runs are per site. Action nodes create ordinary jobs and execute them in sequence.</p>

            <div class="divider"></div>
            <div class="label">Recent Runs</div>
            <div class="stack">
                <?php if ($recentRuns === []): ?>
                    <p class="muted">No runs yet.</p>
                <?php else: ?>
                    <?php foreach ($recentRuns as $run): ?>
                        <a href="/dashboard/workflow-runs/<?= urlencode($run->id) ?>" class="btn ghost" style="justify-content: space-between;">
                            <span><?= htmlspecialchars($run->status) ?></span>
                            <code><?= htmlspecialchars($run->createdAt) ?></code>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</section>

<script>
const WORKFLOW_ID = <?= json_encode($workflow?->id) ?>;
const CSRF_TOKEN = <?= json_encode($csrf->token()) ?>;
const NODE_CATALOG = <?= json_encode($nodeCatalog, JSON_UNESCAPED_SLASHES) ?>;
const state = {
    nodes: <?= json_encode($initialNodes, JSON_UNESCAPED_SLASHES) ?>,
    edges: <?= json_encode($initialEdges, JSON_UNESCAPED_SLASHES) ?>,
    selectedNodeKey: null,
    connecting: null,
    drag: null,
};

const paletteList = document.getElementById('palette-list');
const canvas = document.getElementById('workflow-canvas');
const edgeLayer = document.getElementById('workflow-edge-layer');
const saveButton = document.getElementById('save-workflow-btn');
const deleteNodeButton = document.getElementById('delete-node-btn');
const runWorkflowButton = document.getElementById('run-workflow-btn');
const selectedEmpty = document.getElementById('selected-node-empty');
const selectedSettings = document.getElementById('selected-node-settings');

function slugify(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'node';
}

function generateNodeKey(base) {
    const candidateBase = slugify(base);
    let index = 1;
    let candidate = candidateBase;
    const keys = new Set(state.nodes.map(node => node.node_key));
    while (keys.has(candidate)) {
        index += 1;
        candidate = `${candidateBase}-${index}`;
    }
    return candidate;
}

function parseList(value) {
    return String(value || '')
        .split(',')
        .map(item => item.trim())
        .filter(Boolean);
}

function listToString(items) {
    return Array.isArray(items) ? items.join(', ') : '';
}

function kindFor(type) {
    return NODE_CATALOG[type]?.kind || 'action';
}

function addNode(type) {
    const def = NODE_CATALOG[type];
    if (!def) return;

    const nodeKey = generateNodeKey(def.default_label || type);
    let config = {};
    if (type === 'condition.plugin_updates_available') {
        config = {mode: 'all_with_updates', plugins: [], exclude_plugins: []};
    } else if (type === 'condition.theme_updates_available') {
        config = {mode: 'all_with_updates', themes: [], exclude_themes: []};
    } else if (type === 'plugin.update.selected') {
        config = {plugins: []};
    } else if (type === 'plugin.update.all_except') {
        config = {exclude_plugins: []};
    } else if (type === 'theme.update.selected') {
        config = {themes: []};
    } else if (type === 'theme.update.all_except') {
        config = {exclude_themes: []};
    } else if (type === 'core.update') {
        config = {version: ''};
    }

    state.nodes.push({
        id: '',
        node_key: nodeKey,
        type,
        label: def.default_label || type,
        config,
        position_x: 120 + (state.nodes.length * 30),
        position_y: 120 + (state.nodes.length * 30),
    });
    state.selectedNodeKey = nodeKey;
    render();
}

function setEdge(fromNodeKey, edgeType, toNodeKey) {
    state.edges = state.edges.filter(edge => !(edge.from_node_key === fromNodeKey && edge.edge_type === edgeType));
    state.edges.push({
        id: '',
        from_node_key: fromNodeKey,
        to_node_key: toNodeKey,
        edge_type: edgeType,
    });
}

function deleteSelectedNode() {
    if (!state.selectedNodeKey) return;
    state.nodes = state.nodes.filter(node => node.node_key !== state.selectedNodeKey);
    state.edges = state.edges.filter(edge => edge.from_node_key !== state.selectedNodeKey && edge.to_node_key !== state.selectedNodeKey);
    state.selectedNodeKey = null;
    render();
}

function renderPalette() {
    const groups = {};
    Object.entries(NODE_CATALOG).forEach(([type, def]) => {
        groups[def.category] = groups[def.category] || [];
        groups[def.category].push([type, def]);
    });

    paletteList.innerHTML = Object.entries(groups).map(([category, items]) => `
        <div class="stack">
            <div class="muted" style="text-transform:capitalize;">${category}</div>
            ${items.map(([type, def]) => `<button type="button" class="ghost" data-add-node="${type}" style="justify-content:flex-start;">${def.label}</button>`).join('')}
        </div>
    `).join('<div class="divider"></div>');

    paletteList.querySelectorAll('[data-add-node]').forEach(button => {
        button.addEventListener('click', () => addNode(button.getAttribute('data-add-node')));
    });
}

function renderCanvas() {
    canvas.innerHTML = '';

    state.nodes.forEach(node => {
        const kind = kindFor(node.type);
        const outputs = kind === 'condition' ? ['true', 'false'] : (kind === 'end' ? [] : ['next']);
        const nodeEl = document.createElement('div');
        nodeEl.className = 'panel';
        nodeEl.style.position = 'absolute';
        nodeEl.style.left = `${node.position_x}px`;
        nodeEl.style.top = `${node.position_y}px`;
        nodeEl.style.width = '240px';
        nodeEl.style.padding = '16px';
        nodeEl.style.cursor = 'move';
        nodeEl.style.borderColor = state.selectedNodeKey === node.node_key ? 'rgba(124,92,255,0.75)' : 'var(--line)';
        nodeEl.innerHTML = `
            <div class="stack" data-node-header="${node.node_key}">
                <div class="row" style="justify-content:space-between;">
                    <div class="chip">${node.type}</div>
                    <code>${node.node_key}</code>
                </div>
                <div class="value" style="font-size:1rem;">${escapeHtml(node.label)}</div>
                <div class="muted">${kind}</div>
                <div class="row" style="margin-top:10px; justify-content:space-between;">
                    ${outputs.map(edgeType => `<button type="button" class="ghost sm" data-connect="${node.node_key}" data-edge-type="${edgeType}">${edgeType}</button>`).join('')}
                </div>
            </div>
        `;
        nodeEl.addEventListener('click', (event) => {
            if (event.target instanceof HTMLElement && event.target.dataset.connect) {
                return;
            }

            if (state.connecting && state.connecting.fromNodeKey !== node.node_key) {
                setEdge(state.connecting.fromNodeKey, state.connecting.edgeType, node.node_key);
                state.connecting = null;
            }

            state.selectedNodeKey = node.node_key;
            render();
        });

        const header = nodeEl.querySelector(`[data-node-header="${node.node_key}"]`);
        header.addEventListener('mousedown', (event) => {
            state.drag = {
                nodeKey: node.node_key,
                startX: event.clientX,
                startY: event.clientY,
                originX: node.position_x,
                originY: node.position_y,
            };
        });

        canvas.appendChild(nodeEl);
    });

    canvas.querySelectorAll('[data-connect]').forEach(button => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            state.connecting = {
                fromNodeKey: button.getAttribute('data-connect'),
                edgeType: button.getAttribute('data-edge-type'),
            };
        });
    });

    renderEdges();
}

function renderEdges() {
    edgeLayer.innerHTML = '';
    const nodeMap = Object.fromEntries(state.nodes.map(node => [node.node_key, node]));
    state.edges.forEach(edge => {
        const fromNode = nodeMap[edge.from_node_key];
        const toNode = nodeMap[edge.to_node_key];
        if (!fromNode || !toNode) return;

        const x1 = fromNode.position_x + 240;
        const y1 = fromNode.position_y + 70;
        const x2 = toNode.position_x;
        const y2 = toNode.position_y + 50;
        const midX = (x1 + x2) / 2;

        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', `M ${x1} ${y1} C ${midX} ${y1}, ${midX} ${y2}, ${x2} ${y2}`);
        path.setAttribute('fill', 'none');
        path.setAttribute('stroke', edge.edge_type === 'next' ? '#22d3ee' : (edge.edge_type === 'true' ? '#34d399' : '#f59e0b'));
        path.setAttribute('stroke-width', '3');
        path.setAttribute('opacity', '0.9');
        edgeLayer.appendChild(path);

        const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        label.setAttribute('x', String(midX));
        label.setAttribute('y', String((y1 + y2) / 2 - 6));
        label.setAttribute('fill', '#cbd5e1');
        label.setAttribute('font-size', '12');
        label.setAttribute('text-anchor', 'middle');
        label.textContent = edge.edge_type;
        edgeLayer.appendChild(label);
    });
}

function renderSettings() {
    const node = state.nodes.find(item => item.node_key === state.selectedNodeKey) || null;
    if (!node) {
        selectedEmpty.style.display = 'block';
        selectedSettings.style.display = 'none';
        selectedSettings.innerHTML = '';
        return;
    }

    selectedEmpty.style.display = 'none';
    selectedSettings.style.display = 'block';
    const kind = kindFor(node.type);
    const config = node.config || {};

    let extraFields = '';

    if (node.type === 'plugin.update.selected') {
        extraFields = `<label class="field">Plugins (comma-separated)</label><input data-config-list="plugins" value="${escapeAttr(listToString(config.plugins))}">`;
    } else if (node.type === 'plugin.update.all_except') {
        extraFields = `<label class="field">Exclude Plugins</label><input data-config-list="exclude_plugins" value="${escapeAttr(listToString(config.exclude_plugins))}">`;
    } else if (node.type === 'theme.update.selected') {
        extraFields = `<label class="field">Themes (comma-separated)</label><input data-config-list="themes" value="${escapeAttr(listToString(config.themes))}">`;
    } else if (node.type === 'theme.update.all_except') {
        extraFields = `<label class="field">Exclude Themes</label><input data-config-list="exclude_themes" value="${escapeAttr(listToString(config.exclude_themes))}">`;
    } else if (node.type === 'core.update') {
        extraFields = `<label class="field">Version (optional)</label><input data-config-value="version" value="${escapeAttr(config.version || '')}" placeholder="Leave blank for latest">`;
    } else if (node.type === 'condition.plugin_updates_available') {
        extraFields = `
            <label class="field">Mode</label>
            <select data-config-value="mode">
                ${['selected', 'all_with_updates', 'all_except'].map(mode => `<option value="${mode}" ${config.mode === mode ? 'selected' : ''}>${mode}</option>`).join('')}
            </select>
            <label class="field" style="margin-top:12px;">Plugins</label>
            <input data-config-list="plugins" value="${escapeAttr(listToString(config.plugins))}">
            <label class="field" style="margin-top:12px;">Exclude Plugins</label>
            <input data-config-list="exclude_plugins" value="${escapeAttr(listToString(config.exclude_plugins))}">
        `;
    } else if (node.type === 'condition.theme_updates_available') {
        extraFields = `
            <label class="field">Mode</label>
            <select data-config-value="mode">
                ${['selected', 'all_with_updates', 'all_except'].map(mode => `<option value="${mode}" ${config.mode === mode ? 'selected' : ''}>${mode}</option>`).join('')}
            </select>
            <label class="field" style="margin-top:12px;">Themes</label>
            <input data-config-list="themes" value="${escapeAttr(listToString(config.themes))}">
            <label class="field" style="margin-top:12px;">Exclude Themes</label>
            <input data-config-list="exclude_themes" value="${escapeAttr(listToString(config.exclude_themes))}">
        `;
    }

    selectedSettings.innerHTML = `
        <div class="stack">
            <div class="chip">${kind}</div>
            <label class="field">Label</label>
            <input id="selected-node-label" value="${escapeAttr(node.label)}">
            ${extraFields}
        </div>
    `;

    selectedSettings.querySelector('#selected-node-label').addEventListener('input', (event) => {
        node.label = event.target.value;
        renderCanvas();
    });

    selectedSettings.querySelectorAll('[data-config-list]').forEach(input => {
        input.addEventListener('input', () => {
            node.config = node.config || {};
            node.config[input.dataset.configList] = parseList(input.value);
        });
    });

    selectedSettings.querySelectorAll('[data-config-value]').forEach(input => {
        input.addEventListener('input', () => {
            node.config = node.config || {};
            node.config[input.dataset.configValue] = input.value;
        });
    });
}

function payload() {
    return {
        _token: CSRF_TOKEN,
        name: document.getElementById('wf-name').value.trim(),
        description: document.getElementById('wf-description').value.trim() || null,
        status: document.getElementById('wf-status').value,
        nodes: state.nodes.map(node => ({
            id: node.id || '',
            node_key: node.node_key,
            type: node.type,
            label: node.label,
            config: node.config || {},
            position_x: Math.round(node.position_x),
            position_y: Math.round(node.position_y),
        })),
        edges: state.edges.map(edge => ({
            id: edge.id || '',
            from_node_key: edge.from_node_key,
            to_node_key: edge.to_node_key,
            edge_type: edge.edge_type,
        })),
    };
}

async function saveWorkflow() {
    const endpoint = WORKFLOW_ID ? `/dashboard/api/v1/workflows/${WORKFLOW_ID}` : '/dashboard/api/v1/workflows';
    const method = WORKFLOW_ID ? 'PATCH' : 'POST';
    const response = await persistWorkflow(endpoint, method);
    if (!response) {
        return;
    }

    if (!WORKFLOW_ID && response.data.workflow?.id) {
        window.location.href = `/dashboard/workflows/${response.data.workflow.id}`;
        return;
    }

    window.location.reload();
}

async function persistWorkflow(endpoint, method) {
    const response = await fetch(endpoint, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CSRF_TOKEN,
        },
        body: JSON.stringify(payload()),
    });
    const data = await response.json();
    if (!response.ok || data.status !== 'ok') {
        alert(data.message || 'Failed to save workflow.');
        return null;
    }

    return data;
}

async function runWorkflow() {
    if (!WORKFLOW_ID) {
        alert('Save the workflow before running it.');
        return;
    }

    const siteId = document.getElementById('workflow-run-site').value;
    if (!siteId) {
        alert('Select a site first.');
        return;
    }

    const saved = await persistWorkflow(`/dashboard/api/v1/workflows/${WORKFLOW_ID}`, 'PATCH');
    if (!saved) {
        return;
    }

    const response = await fetch(`/dashboard/api/v1/workflows/${WORKFLOW_ID}/run`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CSRF_TOKEN,
        },
        body: JSON.stringify({_token: CSRF_TOKEN, site_id: siteId}),
    });
    const data = await response.json();
    if (!response.ok || data.status !== 'ok') {
        alert(data.message || 'Failed to run workflow.');
        return;
    }

    window.location.href = `/dashboard/workflow-runs/${data.data.id}`;
}

function render() {
    renderCanvas();
    renderSettings();
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function escapeAttr(value) {
    return escapeHtml(value).replaceAll("'", '&#039;');
}

renderPalette();
render();

saveButton.addEventListener('click', saveWorkflow);
deleteNodeButton.addEventListener('click', deleteSelectedNode);
runWorkflowButton.addEventListener('click', runWorkflow);

window.addEventListener('mousemove', (event) => {
    if (!state.drag) return;
    const node = state.nodes.find(item => item.node_key === state.drag.nodeKey);
    if (!node) return;

    node.position_x = Math.max(20, state.drag.originX + (event.clientX - state.drag.startX));
    node.position_y = Math.max(20, state.drag.originY + (event.clientY - state.drag.startY));
    renderCanvas();
});

window.addEventListener('mouseup', () => {
    state.drag = null;
});
</script>

<?php require __DIR__ . '/../_chrome_end.php'; ?>
