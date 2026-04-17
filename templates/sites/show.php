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

$statusLabel = 'Awaiting first heartbeat';
if ($site->lastHeartbeatAt !== null) {
    $age = time() - strtotime($site->lastHeartbeatAt);
    $statusLabel = $age > ($heartbeatThresholdMinutes * 60) ? 'Heartbeat overdue' : 'Active';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?> Site</title>
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
        .wrap { max-width: 1100px; margin: 0 auto; padding: 32px 20px 48px; }
        header, .topbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
        h1 { margin: 0 0 6px; font: 700 2rem/1.1 Georgia, serif; }
        .muted { color: var(--muted); }
        .nav { display: flex; gap: 12px; flex-wrap: wrap; }
        .button-link, button {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 10px 16px; border-radius: 999px; border: 0;
            background: var(--accent); color: #fff; text-decoration: none; font-weight: 700; cursor: pointer;
        }
        .button-link.alt { background: #e6ede7; color: var(--ink); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-top: 24px; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 18px; padding: 20px; }
        .label { display: block; margin-bottom: 10px; color: var(--muted); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.06em; }
        .value { font-size: 1.05rem; font-weight: 700; word-break: break-word; }
        .chip { display: inline-block; padding: 4px 10px; border-radius: 999px; background: var(--chip); font-size: 0.85rem; font-weight: 700; }
        pre { margin: 0; white-space: pre-wrap; word-break: break-word; font: 0.9rem/1.5 Consolas, monospace; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 12px 10px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        th { color: var(--muted); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.05em; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <h1><?= htmlspecialchars($site->label) ?></h1>
            <p class="muted"><?= htmlspecialchars($site->url) ?></p>
        </div>
        <form method="post" action="/logout">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
            <button type="submit">Log Out</button>
        </form>
    </header>

    <div class="topbar" style="margin-top: 18px;">
        <div class="nav">
            <a class="button-link alt" href="/dashboard">Dashboard</a>
            <a class="button-link alt" href="/dashboard/sites">Sites</a>
            <a class="button-link alt" href="/dashboard/jobs">Jobs</a>
            <a class="button-link alt" href="/dashboard/jobs/create">Create Job</a>
        </div>
        <span class="chip"><?= htmlspecialchars($statusLabel) ?></span>
    </div>

    <section class="grid">
        <article class="panel">
            <span class="label">Site ID</span>
            <div class="value"><?= htmlspecialchars($site->id) ?></div>
        </article>
        <article class="panel">
            <span class="label">Server</span>
            <div class="value"><?= htmlspecialchars($server?->label ?? ('Server #' . $site->serverId)) ?></div>
            <p class="muted"><?= htmlspecialchars($server?->hostname ?? 'Unknown host') ?></p>
        </article>
        <article class="panel">
            <span class="label">Heartbeat</span>
            <div class="value"><?= htmlspecialchars($site->lastHeartbeatAt ?? 'Never') ?></div>
            <p class="muted">Threshold: <?= $heartbeatThresholdMinutes ?> minutes</p>
        </article>
        <article class="panel">
            <span class="label">Versions</span>
            <div class="value">WP <?= htmlspecialchars($site->wpVersion ?? 'n/a') ?> / PHP <?= htmlspecialchars($site->phpVersion ?? 'n/a') ?></div>
            <p class="muted">Registered <?= htmlspecialchars($site->registeredAt) ?></p>
        </article>
    </section>

    <section class="grid">
        <article class="panel">
            <span class="label">WordPress Path</span>
            <div class="value"><?= htmlspecialchars($site->wpPath) ?></div>
        </article>
        <article class="panel">
            <span class="label">Latest Telemetry</span>
            <?php if ($latestTelemetry === null): ?>
                <p class="muted">No telemetry has been received yet.</p>
            <?php else: ?>
                <p class="muted">Received at <?= htmlspecialchars($latestTelemetry['received_at']) ?></p>
                <pre><?= htmlspecialchars((string) json_encode($latestTelemetry['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
            <?php endif; ?>
        </article>
    </section>

    <section class="panel" style="margin-top: 16px;">
        <span class="label">Recent Jobs</span>
        <?php if ($recentJobs === []): ?>
            <p class="muted">No jobs have been recorded for this site yet.</p>
        <?php else: ?>
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
                        <td><?= htmlspecialchars($job->status) ?></td>
                        <td><?= htmlspecialchars($job->createdAt) ?></td>
                        <td><?= htmlspecialchars($job->startedAt ?? 'n/a') ?></td>
                        <td><?= htmlspecialchars($job->completedAt ?? 'n/a') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="panel" style="margin-top: 16px;">
        <span class="label">Agent Activity Log</span>
        <?php if ($agentActivity === []): ?>
            <p class="muted">No agent activity has been logged for this site yet.</p>
        <?php else: ?>
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
                        <td><?= htmlspecialchars($entry['created_at']) ?></td>
                        <td><?= htmlspecialchars($entry['action']) ?></td>
                        <td><?= htmlspecialchars($entry['ip_address'] ?? 'n/a') ?></td>
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
        <?php endif; ?>
    </section>
</div>
</body>
</html>
