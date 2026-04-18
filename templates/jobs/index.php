<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
/** @var list<JetWP\Control\Models\Job> $jobs */
/** @var array<string, string> $siteLabels */
/** @var array<string, string> $filters */
/** @var array{type: string, message: string}|null $flash */

$pageTitle = $appName . ' · Jobs';
$pageEyebrow = 'Orchestration';
$pageHeading = 'Jobs';
$pageLead = 'Create, inspect, retry and cancel queued work across the fleet.';
$activeNav = 'jobs';
$pageHeaderAside = '<a class="btn" href="/dashboard/jobs/create"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>New Job</a>';

require __DIR__ . '/../_chrome.php';
?>

<?php if ($flash !== null): ?>
    <div class="flash <?= htmlspecialchars($flash['type']) ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </div>
<?php endif; ?>

<section class="stack-lg">
    <form method="get" action="/dashboard/jobs" class="panel">
        <div class="label">Filter</div>
        <div class="grid">
            <div>
                <label class="field" for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (['pending', 'running', 'completed', 'failed', 'cancelled'] as $status): ?>
                        <option value="<?= htmlspecialchars($status) ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>>
                            <?= htmlspecialchars($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="field" for="type">Type</label>
                <input id="type" name="type" type="text" value="<?= htmlspecialchars($filters['type'] ?? '') ?>" placeholder="cache.flush">
            </div>

            <div>
                <label class="field" for="site_id">Site ID</label>
                <input id="site_id" name="site_id" type="text" value="<?= htmlspecialchars($filters['site_id'] ?? '') ?>" placeholder="UUID">
            </div>

            <div style="display: flex; align-items: flex-end;">
                <button type="submit">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    Apply
                </button>
            </div>
        </div>
    </form>

    <div class="panel" style="padding: 0;">
        <?php if ($jobs === []): ?>
            <div style="padding: 60px 20px; text-align: center;" class="muted">No jobs matched the current filters.</div>
        <?php else: ?>
            <div class="table-wrap" style="border: 0; background: transparent;">
                <table>
                    <thead>
                    <tr>
                        <th>Type</th>
                        <th>Site</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Attempts</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td>
                                <div class="stack">
                                    <a href="/dashboard/jobs/<?= urlencode($job->id) ?>"><?= htmlspecialchars($job->type) ?></a>
                                    <code style="font-size: 11px; opacity: 0.6;"><?= htmlspecialchars($job->id) ?></code>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($siteLabels[$job->siteId] ?? $job->siteId) ?></td>
                            <td>
                                <span class="chip status-<?= htmlspecialchars($job->status) ?> <?= $job->status === 'running' ? 'live' : '' ?>">
                                    <span class="dot"></span><?= htmlspecialchars($job->status) ?>
                                </span>
                            </td>
                            <td><span class="chip"><?= htmlspecialchars((string) $job->priority) ?></span></td>
                            <td style="font-family:'JetBrains Mono',monospace; font-size:12.5px;"><?= htmlspecialchars($job->attempts . ' / ' . $job->maxAttempts) ?></td>
                            <td style="font-family:'JetBrains Mono',monospace; font-size:12.5px;"><?= htmlspecialchars($job->createdAt) ?></td>
                            <td>
                                <div class="row" style="gap:8px;">
                                    <a class="btn ghost sm" href="/dashboard/jobs/<?= urlencode($job->id) ?>">View</a>
                                    <?php if ($job->status === 'failed'): ?>
                                        <form method="post" action="/dashboard/jobs/<?= urlencode($job->id) ?>/retry" class="inline">
                                            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                                            <button type="submit" class="sm">Retry</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($job->status === 'pending'): ?>
                                        <form method="post" action="/dashboard/jobs/<?= urlencode($job->id) ?>/trigger" class="inline">
                                            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                                            <button type="submit" class="sm">Trigger</button>
                                        </form>
                                        <form method="post" action="/dashboard/jobs/<?= urlencode($job->id) ?>/cancel" class="inline">
                                            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                                            <button type="submit" class="danger sm">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../_chrome_end.php'; ?>
