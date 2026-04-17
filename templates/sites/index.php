<?php

declare(strict_types=1);

use JetWP\Control\Models\Job;
use JetWP\Control\Models\Site;

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
/** @var list<Site> $sites */
/** @var array<int, string> $serverLabels */
/** @var array<string, array{id:int,site_id:string,payload:array,received_at:string}|null> $latestTelemetry */
/** @var array<string, list<Job>> $recentJobs */
/** @var int $heartbeatThresholdMinutes */

$statusInfo = static function (Site $site, ?array $telemetry, int $thresholdMinutes): array {
    if ($site->lastHeartbeatAt === null) {
        return ['label' => 'Awaiting first heartbeat', 'class' => 'warn'];
    }
    $age = time() - strtotime($site->lastHeartbeatAt);
    if ($age > ($thresholdMinutes * 60)) {
        return ['label' => 'Heartbeat overdue', 'class' => 'bad'];
    }
    if ($telemetry !== null) {
        $uptime = $telemetry['payload']['uptime_status'] ?? null;
        if (is_string($uptime) && $uptime !== '') {
            return ['label' => 'Active (' . $uptime . ')', 'class' => 'good live'];
        }
    }
    return ['label' => 'Active', 'class' => 'good live'];
};

$pageTitle = $appName . ' · Sites';
$pageEyebrow = 'Fleet Overview';
$pageHeading = 'Sites';
$pageLead = 'Current site state, latest heartbeat and recent job activity for the internal pilot.';
$activeNav = 'sites';
$pageHeaderAside = '<span class="chip info"><span class="dot"></span>' . count($sites) . ' site' . (count($sites) === 1 ? '' : 's') . ' connected</span>';

require __DIR__ . '/../_chrome.php';
?>

<div class="panel" style="padding: 0;">
    <div class="table-wrap" style="border: 0; background: transparent;">
        <table>
            <thead>
            <tr>
                <th>Site</th>
                <th>Status</th>
                <th>Server</th>
                <th>Last Heartbeat</th>
                <th>Versions</th>
                <th>Recent Jobs</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sites as $site): ?>
                <?php
                $telemetry = $latestTelemetry[$site->id] ?? null;
                $jobs = $recentJobs[$site->id] ?? [];
                $status = $statusInfo($site, $telemetry, $heartbeatThresholdMinutes);
                ?>
                <tr>
                    <td>
                        <div class="stack">
                            <a href="/dashboard/sites/<?= urlencode($site->id) ?>"><?= htmlspecialchars($site->label) ?></a>
                            <span class="muted" style="font-size: 12.5px;"><?= htmlspecialchars($site->url) ?></span>
                            <code style="font-size: 11px; opacity: 0.7;"><?= htmlspecialchars($site->id) ?></code>
                        </div>
                    </td>
                    <td>
                        <span class="chip <?= htmlspecialchars($status['class']) ?>">
                            <span class="dot"></span><?= htmlspecialchars($status['label']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($serverLabels[$site->serverId] ?? ('Server #' . $site->serverId)) ?></td>
                    <td style="font-family: 'JetBrains Mono', monospace; font-size: 12.5px;"><?= htmlspecialchars($site->lastHeartbeatAt ?? 'Never') ?></td>
                    <td>
                        <div class="stack">
                            <span><span class="muted">WP</span> <?= htmlspecialchars($site->wpVersion ?? 'n/a') ?></span>
                            <span><span class="muted">PHP</span> <?= htmlspecialchars($site->phpVersion ?? 'n/a') ?></span>
                        </div>
                    </td>
                    <td>
                        <?php if ($jobs === []): ?>
                            <span class="muted">No jobs yet</span>
                        <?php else: ?>
                            <div class="stack">
                                <?php foreach ($jobs as $job): ?>
                                    <span>
                                        <?= htmlspecialchars($job->type) ?>
                                        <span class="chip status-<?= htmlspecialchars($job->status) ?>" style="margin-left:6px;"><?= htmlspecialchars($job->status) ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($sites === []): ?>
                <tr><td colspan="6" style="text-align:center; padding: 40px;" class="muted">No sites registered yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../_chrome_end.php'; ?>
