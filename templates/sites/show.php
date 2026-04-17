<?php

declare(strict_types=1);

use JetWP\Control\Models\Job;
use JetWP\Control\Models\Server;
use JetWP\Control\Models\Site;

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
/** @var Site $site */
/** @var Server|null $server */
/** @var array{id:int,site_id:string,payload:array,received_at:string}|null $latestTelemetry */
/** @var array{current_version:?string,available_update:?string,rollback_version:?string,has_update:bool} $coreInventory */
/** @var list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string,file:?string}> $pluginInventory */
/** @var list<array{slug:string,name:string,version:string,active:bool,update_available:?string,rollback_version:?string}> $themeInventory */
/** @var array{plugin_count:int,theme_count:int,plugin_updates:int,theme_updates:int} $inventorySummary */
/** @var list<Job> $recentJobs */
/** @var list<array{id:int,user_id:?int,site_id:?string,action:string,details:array,ip_address:?string,created_at:string}> $agentActivity */
/** @var int $heartbeatThresholdMinutes */
/** @var array{type:string,message:string}|null $flash */

$statusClass = 'warn';
$statusLabel = 'Awaiting first heartbeat';
if ($site->lastHeartbeatAt !== null) {
    $age = time() - strtotime($site->lastHeartbeatAt);
    if ($age > ($heartbeatThresholdMinutes * 60)) {
        $statusLabel = 'Heartbeat overdue';
        $statusClass = 'bad';
    } else {
        $statusLabel = 'Active';
        $statusClass = 'good live';
    }
}

$pageTitle = $appName . ' · ' . $site->label;
$pageEyebrow = 'Site Detail';
$pageHeading = $site->label;
$pageLead = '<a href="' . htmlspecialchars($site->url) . '" target="_blank" rel="noopener" style="color:var(--ink-dim); text-decoration: none; border-bottom: 1px dashed var(--line-hi);">' . htmlspecialchars($site->url) . '</a>';
$activeNav = 'sites';
$pageHeaderAside =
    '<span class="chip ' . $statusClass . '"><span class="dot"></span>' . htmlspecialchars($statusLabel) . '</span>'
    . '<a class="btn ghost" href="/dashboard/sites">Back to Sites</a>';

$coreVersion = $coreInventory['current_version'] ?? $site->wpVersion ?? 'n/a';

$activeJobTypes = [];
foreach ($recentJobs as $__job) {
    if ($__job->status === Job::STATUS_PENDING || $__job->status === Job::STATUS_RUNNING) {
        $activeJobTypes[$__job->type] = true;
    }
}
$magicIfActive = static fn (string $type): string => isset($activeJobTypes[$type]) ? ' magic' : '';

require __DIR__ . '/../_chrome.php';
?>

<?php if ($flash !== null): ?>
    <div class="flash <?= htmlspecialchars($flash['type']) === 'success' ? '' : 'error' ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </div>
<?php endif; ?>

<section class="stack-lg">
    <div class="grid">
        <article class="panel">
            <div class="label">Site ID</div>
            <div class="value" style="font-family:'JetBrains Mono',monospace; font-size:0.95rem;"><?= htmlspecialchars($site->id) ?></div>
        </article>
        <article class="panel">
            <div class="label">Server</div>
            <div class="value"><?= htmlspecialchars($server?->label ?? ('Server #' . $site->serverId)) ?></div>
            <p class="muted" style="margin-top:6px"><?= htmlspecialchars($server?->hostname ?? 'Unknown host') ?></p>
        </article>
        <article class="panel">
            <div class="label">Heartbeat</div>
            <div class="value" style="font-family:'JetBrains Mono',monospace; font-size:0.95rem;"><?= htmlspecialchars($site->lastHeartbeatAt ?? 'Never') ?></div>
            <p class="muted" style="margin-top:6px">Threshold: <?= $heartbeatThresholdMinutes ?> minutes</p>
        </article>
        <article class="panel">
            <div class="label">Versions</div>
            <div class="value">WP <?= htmlspecialchars((string) $coreVersion) ?> <span class="muted">/</span> PHP <?= htmlspecialchars($site->phpVersion ?? 'n/a') ?></div>
            <p class="muted" style="margin-top:6px">Registered <?= htmlspecialchars($site->registeredAt) ?></p>
        </article>
        <article class="panel">
            <div class="label">Plugins</div>
            <div class="value"><?= $inventorySummary['plugin_count'] ?></div>
            <p class="muted" style="margin-top:6px"><?= $inventorySummary['plugin_updates'] ?> with updates available</p>
        </article>
        <article class="panel">
            <div class="label">Themes</div>
            <div class="value"><?= $inventorySummary['theme_count'] ?></div>
            <p class="muted" style="margin-top:6px"><?= $inventorySummary['theme_updates'] ?> with updates available</p>
        </article>
    </div>

    <div class="grid cols-2">
        <article class="panel glow">
            <div class="row" style="align-items:flex-start;">
                <div class="stack">
                    <div class="label">WordPress Core</div>
                    <div class="value">Version <?= htmlspecialchars((string) $coreVersion) ?></div>
                    <div class="row">
                        <?php if (($coreInventory['available_update'] ?? null) !== null): ?>
                            <span class="chip warn"><span class="dot"></span>Update available: <?= htmlspecialchars((string) $coreInventory['available_update']) ?></span>
                        <?php else: ?>
                            <span class="chip good"><span class="dot"></span>Up to date</span>
                        <?php endif; ?>
                        <?php if (($coreInventory['rollback_version'] ?? null) !== null): ?>
                            <span class="chip info"><span class="dot"></span>Rollback target: <?= htmlspecialchars((string) $coreInventory['rollback_version']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="spacer"></div>
                <div class="row">
                    <form method="post" action="/dashboard/sites/<?= urlencode($site->id) ?>/actions">
                        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                        <input type="hidden" name="action_type" value="core.update">
                        <button type="submit" class="magic-toggle<?= $magicIfActive('core.update') ?>" data-magic-type="core.update"><span class="magic-label">Update Core</span></button>
                    </form>
                    <?php if (($coreInventory['rollback_version'] ?? null) !== null): ?>
                        <form method="post" action="/dashboard/sites/<?= urlencode($site->id) ?>/actions">
                            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                            <input type="hidden" name="action_type" value="core.rollback">
                            <button type="submit" class="ghost">Rollback Core</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="divider"></div>
            <div class="stack">
                <div class="label">WordPress Path</div>
                <code><?= htmlspecialchars($site->wpPath) ?></code>
                <?php if ($latestTelemetry !== null): ?>
                    <p class="muted">Latest telemetry received at <code><?= htmlspecialchars($latestTelemetry['received_at']) ?></code></p>
                <?php else: ?>
                    <p class="muted">No telemetry has been received yet.</p>
                <?php endif; ?>
            </div>
        </article>

        <article class="panel">
            <div class="label">Bulk Notes</div>
            <div class="stack" style="gap:10px;">
                <p class="muted">Bulk actions queue one job per selected plugin or theme. The rollback target comes from the most recent older version seen in telemetry history for this site.</p>
                <p class="muted">If rollback is unavailable for a row, JetWP has not yet observed an older version for that component.</p>
            </div>
        </article>
    </div>

    <article class="panel">
        <div class="row" style="margin-bottom:14px;">
            <div class="stack">
                <div class="label">Installed Plugins</div>
                <p class="muted" style="margin:0;">Name, slug, version and fast actions for plugins on this site.</p>
            </div>
            <div class="spacer"></div>
            <form method="post" action="/dashboard/sites/<?= urlencode($site->id) ?>/actions" id="plugin-bulk-form" class="row">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                <button type="submit" name="action_type" value="plugin.update" class="magic-toggle<?= $magicIfActive('plugin.update') ?>" data-magic-type="plugin.update"><span class="magic-label">Update Selected</span></button>
                <button type="submit" name="action_type" value="plugin.rollback" class="ghost">Rollback Selected</button>
            </form>
        </div>

        <?php if ($pluginInventory === []): ?>
            <p class="muted">No plugin inventory is available yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th style="width:48px;">Select</th>
                        <th>Plugin</th>
                        <th>Installed</th>
                        <th>Status</th>
                        <th>Update</th>
                        <th>Rollback</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pluginInventory as $plugin): ?>
                        <tr>
                            <td>
                                <input type="checkbox" form="plugin-bulk-form" name="slugs[]" value="<?= htmlspecialchars($plugin['slug']) ?>">
                            </td>
                            <td>
                                <div class="stack">
                                    <strong style="color:var(--ink)"><?= htmlspecialchars($plugin['name']) ?></strong>
                                    <code><?= htmlspecialchars($plugin['slug']) ?></code>
                                    <?php if (($plugin['file'] ?? null) !== null): ?>
                                        <span class="muted" style="font-size:12px;"><?= htmlspecialchars((string) $plugin['file']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><code><?= htmlspecialchars($plugin['version'] !== '' ? $plugin['version'] : 'n/a') ?></code></td>
                            <td>
                                <span class="chip <?= $plugin['active'] ? 'good' : '' ?>">
                                    <span class="dot"></span><?= $plugin['active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($plugin['update_available'] !== null): ?>
                                    <span class="chip warn"><span class="dot"></span><?= htmlspecialchars($plugin['update_available']) ?></span>
                                <?php else: ?>
                                    <span class="muted">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($plugin['rollback_version'] !== null): ?>
                                    <span class="chip info"><span class="dot"></span><?= htmlspecialchars($plugin['rollback_version']) ?></span>
                                <?php else: ?>
                                    <span class="muted">Unavailable</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row">
                                    <?php if ($plugin['update_available'] !== null): ?>
                                        <form method="post" action="/dashboard/sites/<?= urlencode($site->id) ?>/actions" class="inline">
                                            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                                            <input type="hidden" name="action_type" value="plugin.update">
                                            <input type="hidden" name="slugs[]" value="<?= htmlspecialchars($plugin['slug']) ?>">
                                            <button type="submit" class="sm">Update</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($plugin['rollback_version'] !== null): ?>
                                        <form method="post" action="/dashboard/sites/<?= urlencode($site->id) ?>/actions" class="inline">
                                            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                                            <input type="hidden" name="action_type" value="plugin.rollback">
                                            <input type="hidden" name="slugs[]" value="<?= htmlspecialchars($plugin['slug']) ?>">
                                            <button type="submit" class="ghost sm">Rollback</button>
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
    </article>

    <article class="panel">
        <div class="row" style="margin-bottom:14px;">
            <div class="stack">
                <div class="label">Installed Themes</div>
                <p class="muted" style="margin:0;">Theme inventory with the same quick actions and bulk flow.</p>
            </div>
            <div class="spacer"></div>
            <form method="post" action="/dashboard/sites/<?= urlencode($site->id) ?>/actions" id="theme-bulk-form" class="row">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                <button type="submit" name="action_type" value="theme.update" class="magic-toggle<?= $magicIfActive('theme.update') ?>" data-magic-type="theme.update"><span class="magic-label">Update Selected</span></button>
                <button type="submit" name="action_type" value="theme.rollback" class="ghost">Rollback Selected</button>
            </form>
        </div>

        <?php if ($themeInventory === []): ?>
            <p class="muted">No theme inventory is available yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th style="width:48px;">Select</th>
                        <th>Theme</th>
                        <th>Installed</th>
                        <th>Status</th>
                        <th>Update</th>
                        <th>Rollback</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($themeInventory as $theme): ?>
                        <tr>
                            <td>
                                <input type="checkbox" form="theme-bulk-form" name="slugs[]" value="<?= htmlspecialchars($theme['slug']) ?>">
                            </td>
                            <td>
                                <div class="stack">
                                    <strong style="color:var(--ink)"><?= htmlspecialchars($theme['name']) ?></strong>
                                    <code><?= htmlspecialchars($theme['slug']) ?></code>
                                </div>
                            </td>
                            <td><code><?= htmlspecialchars($theme['version'] !== '' ? $theme['version'] : 'n/a') ?></code></td>
                            <td>
                                <span class="chip <?= $theme['active'] ? 'good' : '' ?>">
                                    <span class="dot"></span><?= $theme['active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($theme['update_available'] !== null): ?>
                                    <span class="chip warn"><span class="dot"></span><?= htmlspecialchars($theme['update_available']) ?></span>
                                <?php else: ?>
                                    <span class="muted">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($theme['rollback_version'] !== null): ?>
                                    <span class="chip info"><span class="dot"></span><?= htmlspecialchars($theme['rollback_version']) ?></span>
                                <?php else: ?>
                                    <span class="muted">Unavailable</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row">
                                    <?php if ($theme['update_available'] !== null): ?>
                                        <form method="post" action="/dashboard/sites/<?= urlencode($site->id) ?>/actions" class="inline">
                                            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                                            <input type="hidden" name="action_type" value="theme.update">
                                            <input type="hidden" name="slugs[]" value="<?= htmlspecialchars($theme['slug']) ?>">
                                            <button type="submit" class="sm">Update</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($theme['rollback_version'] !== null): ?>
                                        <form method="post" action="/dashboard/sites/<?= urlencode($site->id) ?>/actions" class="inline">
                                            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                                            <input type="hidden" name="action_type" value="theme.rollback">
                                            <input type="hidden" name="slugs[]" value="<?= htmlspecialchars($theme['slug']) ?>">
                                            <button type="submit" class="ghost sm">Rollback</button>
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
    </article>

    <article class="panel">
        <div class="label">Recent Jobs</div>
        <?php if ($recentJobs === []): ?>
            <p class="muted">No jobs have been recorded for this site yet.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:10px;">
                <table>
                    <thead>
                    <tr>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Started</th>
                        <th>Completed</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentJobs as $job): ?>
                        <tr>
                            <td><a href="/dashboard/jobs/<?= urlencode($job->id) ?>"><?= htmlspecialchars($job->type) ?></a></td>
                            <td><span class="chip status-<?= htmlspecialchars($job->status) ?>"><?= htmlspecialchars($job->status) ?></span></td>
                            <td style="font-family:'JetBrains Mono',monospace; font-size:12.5px;"><?= htmlspecialchars($job->createdAt) ?></td>
                            <td style="font-family:'JetBrains Mono',monospace; font-size:12.5px;"><?= htmlspecialchars($job->startedAt ?? 'n/a') ?></td>
                            <td style="font-family:'JetBrains Mono',monospace; font-size:12.5px;"><?= htmlspecialchars($job->completedAt ?? 'n/a') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel">
        <div class="label">Agent Activity Log</div>
        <?php if ($agentActivity === []): ?>
            <p class="muted">No agent activity has been logged for this site yet.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:10px;">
                <table>
                    <thead>
                    <tr>
                        <th>Time</th>
                        <th>Action</th>
                        <th>IP</th>
                        <th>Details</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($agentActivity as $entry): ?>
                        <tr>
                            <td style="font-family:'JetBrains Mono',monospace; font-size:12.5px;"><?= htmlspecialchars($entry['created_at']) ?></td>
                            <td><span class="chip"><?= htmlspecialchars($entry['action']) ?></span></td>
                            <td><code><?= htmlspecialchars($entry['ip_address'] ?? 'n/a') ?></code></td>
                            <td>
                                <?php if ($entry['details'] === []): ?>
                                    <span class="muted">No details</span>
                                <?php else: ?>
                                    <pre><?= htmlspecialchars((string) json_encode($entry['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel">
        <div class="label">Latest Telemetry</div>
        <?php if ($latestTelemetry === null): ?>
            <p class="muted">No telemetry has been received yet.</p>
        <?php else: ?>
            <p class="muted" style="margin-bottom:10px;">Received at <code><?= htmlspecialchars($latestTelemetry['received_at']) ?></code></p>
            <pre><?= htmlspecialchars((string) json_encode($latestTelemetry['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        <?php endif; ?>
    </article>
</section>

<style>
    /* ======= Magic buttons (active while job is pending/running) ======= */
    button.magic-toggle {
        position: relative;
        overflow: hidden;
        isolation: isolate;
    }
    button.magic-toggle .magic-label { position: relative; z-index: 2; }

    button.magic-toggle.magic {
        background: linear-gradient(135deg, #7c5cff, #22d3ee 55%, #34d399);
        background-size: 220% 220%;
        animation: magic-hue 6s ease infinite, magic-cast 1.6s ease-in-out infinite;
        box-shadow:
            0 10px 28px -10px rgba(124,92,255,0.75),
            0 0 0 1px rgba(255,255,255,0.1) inset,
            0 0 22px -6px rgba(34,211,238,0.55);
    }

    /* Shimmer sweep only while active */
    button.magic-toggle.magic::before {
        content: '';
        position: absolute; inset: 0;
        border-radius: inherit;
        background: linear-gradient(
            110deg,
            transparent 20%,
            rgba(255,255,255,0.55) 48%,
            rgba(255,255,255,0.0) 60%
        );
        transform: translateX(-120%);
        animation: magic-shimmer 2.4s ease-in-out infinite;
        pointer-events: none;
        mix-blend-mode: screen;
        z-index: 1;
    }
    /* Inner aura (clipped by overflow:hidden, stays inside the button) */
    button.magic-toggle.magic::after {
        content: '';
        position: absolute; inset: 0;
        border-radius: inherit;
        background: conic-gradient(from 0deg,
            rgba(124,92,255,0.55),
            rgba(34,211,238,0.55),
            rgba(52,211,153,0.55),
            rgba(244,114,182,0.55),
            rgba(124,92,255,0.55));
        filter: blur(12px);
        opacity: 0.55;
        z-index: 0;
        animation: magic-spin 5s linear infinite;
        pointer-events: none;
    }

    @keyframes magic-shimmer {
        0%   { transform: translateX(-120%); }
        55%  { transform: translateX(120%); }
        100% { transform: translateX(120%); }
    }
    @keyframes magic-hue {
        0%   { background-position: 0% 50%; }
        50%  { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }
    @keyframes magic-spin { to { transform: rotate(360deg); } }
    @keyframes magic-cast {
        0%,100% { box-shadow: 0 0 0 0 rgba(124,92,255,0.55), 0 14px 36px -12px rgba(124,92,255,0.8); }
        50%     { box-shadow: 0 0 0 10px rgba(124,92,255,0),   0 18px 44px -12px rgba(34,211,238,0.95); }
    }

    @media (prefers-reduced-motion: reduce) {
        button.magic-toggle.magic,
        button.magic-toggle.magic::before,
        button.magic-toggle.magic::after { animation: none !important; }
    }
</style>
<script>
(function () {
    var siteId = <?= json_encode($site->id) ?>;
    var endpoint = '/dashboard/api/v1/jobs?site_id=' + encodeURIComponent(siteId);
    var buttons = document.querySelectorAll('button.magic-toggle[data-magic-type]');
    if (buttons.length === 0) return;

    var pollMs = 3000;
    var timer = null;

    function apply(activeTypes) {
        buttons.forEach(function (btn) {
            var type = btn.getAttribute('data-magic-type');
            btn.classList.toggle('magic', activeTypes.has(type));
        });
    }

    function poll() {
        fetch(endpoint, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (payload) {
                if (!payload || payload.status !== 'ok' || !Array.isArray(payload.data)) return;
                var active = new Set();
                payload.data.forEach(function (job) {
                    if (job.status === 'pending' || job.status === 'running') {
                        active.add(job.type);
                    }
                });
                apply(active);
            })
            .catch(function () { /* ignore */ });
    }

    function start() {
        if (timer !== null) return;
        poll();
        timer = setInterval(poll, pollMs);
    }
    function stop() {
        if (timer === null) return;
        clearInterval(timer);
        timer = null;
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) stop(); else start();
    });

    start();
})();
</script>
<?php require __DIR__ . '/../_chrome_end.php'; ?>
