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

$statusLabel = static function (Site $site, ?array $telemetry, int $thresholdMinutes): string {
    if ($site->lastHeartbeatAt === null) {
        return 'Awaiting first heartbeat';
    }

    $age = time() - strtotime($site->lastHeartbeatAt);
    if ($age > ($thresholdMinutes * 60)) {
        return 'Heartbeat overdue';
    }

    if ($telemetry !== null) {
        $uptime = $telemetry['payload']['uptime_status'] ?? null;
        if (is_string($uptime) && $uptime !== '') {
            return 'Active (' . $uptime . ')';
        }
    }

    return 'Active';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?> Sites</title>
    <style>
        :root {
            --bg: #f3f5ef;
            --panel: #fff;
            --ink: #17221b;
            --muted: #5a675f;
            --line: #dbe2da;
            --accent: #184e3a;
            --chip: #edf3ee;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font-family: "Segoe UI", sans-serif; }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 32px 20px 48px; }
        header, .topbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        h1 { margin: 0 0 6px; font: 700 2rem/1.1 Georgia, serif; }
        p { margin: 0; color: var(--muted); }
        .actions, .nav { display: flex; gap: 12px; flex-wrap: wrap; }
        .button-link, button {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 10px 16px; border-radius: 999px; border: 0;
            background: var(--accent); color: #fff; text-decoration: none; font-weight: 700; cursor: pointer;
        }
        .button-link.alt { background: #e6ede7; color: var(--ink); }
        table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--line); border-radius: 18px; overflow: hidden; margin-top: 24px; }
        th, td { padding: 14px 16px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        th { background: #f8faf7; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
        tr:last-child td { border-bottom: 0; }
        .chip { display: inline-block; padding: 4px 10px; border-radius: 999px; background: var(--chip); font-size: 0.85rem; font-weight: 700; }
        .stack { display: grid; gap: 6px; }
        code { font-family: Consolas, monospace; font-size: 0.9rem; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <h1>Sites</h1>
            <p>Current site state, latest heartbeat and recent job activity for the internal pilot.</p>
        </div>
        <form method="post" action="/logout">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
            <button type="submit">Log Out</button>
        </form>
    </header>

    <div class="topbar" style="margin-top: 18px;">
        <div class="nav">
            <a class="button-link alt" href="/dashboard">Dashboard</a>
            <a class="button-link alt" href="/dashboard/jobs">Jobs</a>
            <a class="button-link alt" href="/dashboard/jobs/create">Create Job</a>
        </div>
        <p><?= count($sites) ?> site(s) in Control Plane</p>
    </div>

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
            <?php $telemetry = $latestTelemetry[$site->id] ?? null; ?>
            <?php $jobs = $recentJobs[$site->id] ?? []; ?>
            <tr>
                <td>
                    <div class="stack">
                        <strong><a href="/dashboard/sites/<?= urlencode($site->id) ?>"><?= htmlspecialchars($site->label) ?></a></strong>
                        <span><?= htmlspecialchars($site->url) ?></span>
                        <code><?= htmlspecialchars($site->id) ?></code>
                    </div>
                </td>
                <td>
                    <span class="chip"><?= htmlspecialchars($statusLabel($site, $telemetry, $heartbeatThresholdMinutes)) ?></span>
                </td>
                <td><?= htmlspecialchars($serverLabels[$site->serverId] ?? ('Server #' . $site->serverId)) ?></td>
                <td><?= htmlspecialchars($site->lastHeartbeatAt ?? 'Never') ?></td>
                <td>
                    <div class="stack">
                        <span>WP: <?= htmlspecialchars($site->wpVersion ?? 'n/a') ?></span>
                        <span>PHP: <?= htmlspecialchars($site->phpVersion ?? 'n/a') ?></span>
                    </div>
                </td>
                <td>
                    <?php if ($jobs === []): ?>
                        <span class="muted">No jobs yet</span>
                    <?php else: ?>
                        <div class="stack">
                            <?php foreach ($jobs as $job): ?>
                                <span><?= htmlspecialchars($job->type) ?> (<?= htmlspecialchars($job->status) ?>)</span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
