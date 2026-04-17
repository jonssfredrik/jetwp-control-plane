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
/** @var list<Job> $recentJobs */
/** @var list<array{id:int,user_id:?int,site_id:?string,action:string,details:array,ip_address:?string,created_at:string}> $agentActivity */
/** @var int $heartbeatThresholdMinutes */

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
$pageHeaderAside = '<span class="chip ' . $statusClass . '"><span class="dot"></span>' . htmlspecialchars($statusLabel) . '</span>';

require __DIR__ . '/../_chrome.php';
?>

<section class="stack-lg">
    <div class="grid">
        <article class="panel">
            <div class="label">Site ID</div>
            <div class="value" style="font-family: 'JetBrains Mono', monospace; font-size: 0.95rem;"><?= htmlspecialchars($site->id) ?></div>
        </article>
        <article class="panel">
            <div class="label">Server</div>
            <div class="value"><?= htmlspecialchars($server?->label ?? ('Server #' . $site->serverId)) ?></div>
            <p class="muted" style="margin-top:6px"><?= htmlspecialchars($server?->hostname ?? 'Unknown host') ?></p>
        </article>
        <article class="panel">
            <div class="label">Heartbeat</div>
            <div class="value" style="font-family: 'JetBrains Mono', monospace; font-size: 0.95rem;"><?= htmlspecialchars($site->lastHeartbeatAt ?? 'Never') ?></div>
            <p class="muted" style="margin-top:6px">Threshold: <?= $heartbeatThresholdMinutes ?> minutes</p>
        </article>
        <article class="panel">
            <div class="label">Versions</div>
            <div class="value">WP <?= htmlspecialchars($site->wpVersion ?? 'n/a') ?> <span class="muted">/</span> PHP <?= htmlspecialchars($site->phpVersion ?? 'n/a') ?></div>
            <p class="muted" style="margin-top:6px">Registered <?= htmlspecialchars($site->registeredAt) ?></p>
        </article>
    </div>

    <div class="grid cols-2">
        <article class="panel">
            <div class="label">WordPress Path</div>
            <div class="value" style="font-family: 'JetBrains Mono', monospace; font-size: 0.95rem;"><?= htmlspecialchars($site->wpPath) ?></div>
        </article>
        <article class="panel">
            <div class="label">Latest Telemetry</div>
            <?php if ($latestTelemetry === null): ?>
                <p class="muted">No telemetry has been received yet.</p>
            <?php else: ?>
                <p class="muted" style="margin-bottom: 10px;">Received at <code><?= htmlspecialchars($latestTelemetry['received_at']) ?></code></p>
                <pre><?= htmlspecialchars((string) json_encode($latestTelemetry['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            <?php endif; ?>
        </article>
    </div>

    <article class="panel">
        <div class="label">Recent Jobs</div>
        <?php if ($recentJobs === []): ?>
            <p class="muted">No jobs have been recorded for this site yet.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top: 10px;">
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
            <div class="table-wrap" style="margin-top: 10px;">
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
</section>

<?php require __DIR__ . '/../_chrome_end.php'; ?>
