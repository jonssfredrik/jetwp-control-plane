<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
/** @var JetWP\Control\Models\WorkflowRun $run */
/** @var JetWP\Control\Models\Workflow|null $workflow */
/** @var JetWP\Control\Models\Site|null $site */
/** @var list<JetWP\Control\Models\WorkflowRunStep> $steps */

$pageTitle = $appName . ' · Workflow Run';
$pageEyebrow = 'Workflow Run';
$pageHeading = $workflow?->name ?? 'Workflow Run';
$pageLead = '<code style="color:var(--ink-dim)">' . htmlspecialchars($run->id) . '</code>';
$activeNav = 'workflows';
$pageHeaderAside = '<a class="btn ghost" href="/dashboard/workflows/' . urlencode($run->workflowId) . '">Back to Workflow</a>';

require __DIR__ . '/../_chrome.php';
?>

<section class="stack-lg">
    <div class="grid">
        <article class="panel">
            <div class="label">Status</div>
            <div class="value"><?= htmlspecialchars($run->status) ?></div>
        </article>
        <article class="panel">
            <div class="label">Site</div>
            <div class="value"><?= htmlspecialchars($site?->label ?? $run->siteId) ?></div>
            <p class="muted" style="margin-top:6px;"><?= htmlspecialchars($site?->url ?? $run->siteId) ?></p>
        </article>
        <article class="panel">
            <div class="label">Started</div>
            <div class="value" style="font-family:'JetBrains Mono',monospace; font-size:0.95rem;"><?= htmlspecialchars($run->startedAt ?? 'N/A') ?></div>
        </article>
        <article class="panel">
            <div class="label">Completed</div>
            <div class="value" style="font-family:'JetBrains Mono',monospace; font-size:0.95rem;"><?= htmlspecialchars($run->completedAt ?? 'Not completed') ?></div>
        </article>
    </div>

    <div class="panel" style="padding:0;">
        <div class="table-wrap" style="border:0; background:transparent;">
            <table>
                <thead>
                <tr>
                    <th>Node</th>
                    <th>Status</th>
                    <th>Output</th>
                    <th>Error</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($steps as $step): ?>
                    <tr>
                        <td>
                            <div class="stack">
                                <code><?= htmlspecialchars($step->nodeKey) ?></code>
                                <span><?= htmlspecialchars($step->nodeType) ?></span>
                            </div>
                        </td>
                        <td><span class="chip"><?= htmlspecialchars($step->status) ?></span></td>
                        <td>
                            <?php
                            $output = $step->output ?? [];
                            $jobs = is_array($output['jobs'] ?? null) ? $output['jobs'] : [];
                            ?>
                            <?php if ($jobs !== []): ?>
                                <div class="stack">
                                    <?php foreach ($jobs as $jobInfo): ?>
                                        <?php if (is_array($jobInfo) && isset($jobInfo['job_id'])): ?>
                                            <a href="/dashboard/jobs/<?= urlencode((string) $jobInfo['job_id']) ?>">
                                                <?= htmlspecialchars((string) ($jobInfo['type'] ?? 'job')) ?> · <?= htmlspecialchars((string) $jobInfo['job_id']) ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <pre><?= htmlspecialchars(json_encode($step->output ?? (object) [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
                            <?php endif; ?>
                        </td>
                        <td><pre><?= htmlspecialchars($step->errorOutput ?? '') ?></pre></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../_chrome_end.php'; ?>
