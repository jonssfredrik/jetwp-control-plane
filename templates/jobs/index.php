<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
/** @var list<JetWP\Control\Models\Job> $jobs */
/** @var array<string, string> $siteLabels */
/** @var array<string, string> $filters */
/** @var array{type: string, message: string}|null $flash */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?> Jobs</title>
    <style>
        :root {
            --bg: #f5f7f2;
            --panel: #ffffff;
            --ink: #112015;
            --muted: #4d5d53;
            --accent: #184e3a;
            --line: #dfe7de;
            --good: #1f7a45;
            --warn: #9a6700;
            --bad: #9f2a2a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: linear-gradient(180deg, rgba(24, 78, 58, 0.08), transparent 220px), var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", sans-serif;
        }
        .wrap {
            max-width: 1180px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }
        .topbar, .toolbar, .filters, .row-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .topbar {
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .toolbar {
            margin-bottom: 18px;
            justify-content: space-between;
        }
        h1 {
            margin: 0 0 6px;
            font: 700 2rem/1.1 Georgia, serif;
        }
        .muted { color: var(--muted); margin: 0; }
        a, a:visited { color: var(--accent); text-decoration: none; }
        .button, button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border: 0;
            border-radius: 999px;
            background: var(--accent);
            color: #fff;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
        }
        .button.secondary {
            background: #e8efe8;
            color: var(--ink);
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 10px 28px rgba(17, 32, 21, 0.05);
        }
        .flash {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #edf5ee;
        }
        .flash.error {
            background: #fdeaea;
            border-color: #efc7c7;
        }
        .filters {
            margin-bottom: 18px;
        }
        label {
            display: block;
            font-size: 0.9rem;
            color: var(--muted);
            margin-bottom: 6px;
        }
        select, input {
            min-width: 180px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            font: inherit;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }
        th {
            color: var(--muted);
            font-size: 0.86rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 0.83rem;
            font-weight: 700;
            background: #eef2ee;
        }
        .status-pending { color: var(--warn); }
        .status-running { color: var(--accent); }
        .status-completed { color: var(--good); }
        .status-failed, .status-cancelled { color: var(--bad); }
        .empty {
            padding: 28px;
            text-align: center;
            color: var(--muted);
        }
        form.inline { display: inline; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div>
            <h1>Jobs</h1>
            <p class="muted">Create, inspect, retry and cancel queued work.</p>
        </div>
        <form method="post" action="/logout">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
            <button type="submit">Log Out</button>
        </form>
    </div>

    <div class="toolbar">
        <div class="muted">Signed in as <?= htmlspecialchars($user->username) ?></div>
        <div class="row-actions">
            <a class="button secondary" href="/dashboard">Dashboard</a>
            <a class="button" href="/dashboard/jobs/create">Create Job</a>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <form method="get" action="/dashboard/jobs" class="filters">
            <div>
                <label for="status">Status</label>
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
                <label for="type">Type</label>
                <input id="type" name="type" type="text" value="<?= htmlspecialchars($filters['type'] ?? '') ?>" placeholder="cache.flush">
            </div>

            <div>
                <label for="site_id">Site ID</label>
                <input id="site_id" name="site_id" type="text" value="<?= htmlspecialchars($filters['site_id'] ?? '') ?>" placeholder="UUID">
            </div>

            <div style="align-self: end;">
                <button type="submit">Filter</button>
            </div>
        </form>

        <?php if ($jobs === []): ?>
            <div class="empty">No jobs matched the current filters.</div>
        <?php else: ?>
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
                            <a href="/dashboard/jobs/<?= urlencode($job->id) ?>"><?= htmlspecialchars($job->type) ?></a>
                            <div class="muted"><?= htmlspecialchars($job->id) ?></div>
                        </td>
                        <td>
                            <?= htmlspecialchars($siteLabels[$job->siteId] ?? $job->siteId) ?>
                        </td>
                        <td>
                            <span class="badge status-<?= htmlspecialchars($job->status) ?>"><?= htmlspecialchars($job->status) ?></span>
                        </td>
                        <td><?= htmlspecialchars((string) $job->priority) ?></td>
                        <td><?= htmlspecialchars($job->attempts . ' / ' . $job->maxAttempts) ?></td>
                        <td><?= htmlspecialchars($job->createdAt) ?></td>
                        <td class="row-actions">
                            <a class="button secondary" href="/dashboard/jobs/<?= urlencode($job->id) ?>">View</a>
                            <?php if ($job->status === 'failed'): ?>
                                <form method="post" action="/dashboard/jobs/<?= urlencode($job->id) ?>/retry" class="inline">
                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                                    <button type="submit">Retry</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($job->status === 'pending'): ?>
                                <form method="post" action="/dashboard/jobs/<?= urlencode($job->id) ?>/cancel" class="inline">
                                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                                    <button type="submit">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
