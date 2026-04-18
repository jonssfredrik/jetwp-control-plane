<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
/** @var list<JetWP\Control\Models\Workflow> $workflows */
/** @var array{type: string, message: string}|null $flash */

$pageTitle = $appName . ' · Workflows';
$pageEyebrow = 'Automation';
$pageHeading = 'Workflows';
$pageLead = 'Visual job orchestration for a single site at a time, with typed conditions and run history.';
$activeNav = 'workflows';
$pageHeaderAside = '<a class="btn" href="/dashboard/workflows/create"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>New Workflow</a>';

require __DIR__ . '/../_chrome.php';
?>

<?php if ($flash !== null): ?>
    <div class="flash <?= htmlspecialchars($flash['type']) ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </div>
<?php endif; ?>

<section class="stack-lg">
    <div class="panel">
        <div class="label">Overview</div>
        <p class="muted">Use the builder to define a DAG of typed nodes. Runs reuse the existing jobs system under the hood.</p>
    </div>

    <div class="panel" style="padding: 0;">
        <?php if ($workflows === []): ?>
            <div style="padding: 60px 20px; text-align: center;" class="muted">No workflows created yet.</div>
        <?php else: ?>
            <div class="table-wrap" style="border: 0; background: transparent;">
                <table>
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($workflows as $workflow): ?>
                        <tr>
                            <td>
                                <div class="stack">
                                    <a href="/dashboard/workflows/<?= urlencode($workflow->id) ?>"><?= htmlspecialchars($workflow->name) ?></a>
                                    <span class="muted"><?= htmlspecialchars($workflow->description ?? 'No description.') ?></span>
                                </div>
                            </td>
                            <td><span class="chip"><?= htmlspecialchars($workflow->status) ?></span></td>
                            <td style="font-family:'JetBrains Mono',monospace; font-size:12.5px;"><?= htmlspecialchars($workflow->updatedAt) ?></td>
                            <td><a class="btn ghost sm" href="/dashboard/workflows/<?= urlencode($workflow->id) ?>">Open Builder</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../_chrome_end.php'; ?>
