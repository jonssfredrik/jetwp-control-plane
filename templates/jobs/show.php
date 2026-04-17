<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
/** @var JetWP\Control\Models\Job $job */
/** @var JetWP\Control\Models\Site|null $site */
/** @var array{type: string, message: string}|null $flash */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?> Job</title>
    <style>
        :root {
            --bg: #f5f7f2;
            --panel: #ffffff;
            --ink: #112015;
            --muted: #4d5d53;
            --accent: #184e3a;
            --line: #dfe7de;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: linear-gradient(180deg, rgba(24, 78, 58, 0.08), transparent 220px), var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", sans-serif;
        }
        .wrap {
            max-width: 1024px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }
        .topbar, .actions, .meta {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        .topbar { margin-bottom: 20px; }
        h1 { margin: 0 0 6px; font: 700 2rem/1.1 Georgia, serif; }
        .muted { color: var(--muted); margin: 0; }
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
        .button.secondary { background: #e8efe8; color: var(--ink); }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 22px;
            margin-bottom: 18px;
            box-shadow: 0 10px 28px rgba(17, 32, 21, 0.05);
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }
        .label {
            display: block;
            color: var(--muted);
            font-size: 0.86rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 8px;
        }
        .value { font-size: 1rem; font-weight: 700; }
        pre {
            margin: 0;
            padding: 16px;
            border-radius: 14px;
            background: #f3f6f3;
            border: 1px solid var(--line);
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-word;
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
        form.inline { display: inline; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div>
            <h1><?= htmlspecialchars($job->type) ?></h1>
            <p class="muted"><?= htmlspecialchars($job->id) ?></p>
        </div>
        <div class="actions">
            <a class="button secondary" href="/dashboard/jobs">Back to Jobs</a>
            <form method="post" action="/logout" class="inline">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                <button type="submit">Log Out</button>
            </form>
        </div>
    </div>

    <?php if ($flash !== null): ?>
        <div class="flash <?= htmlspecialchars($flash['type']) ?>">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="meta">
            <div class="muted">Signed in as <?= htmlspecialchars($user->username) ?></div>
            <div class="actions">
                <?php if ($job->status === 'failed'): ?>
                    <form method="post" action="/dashboard/jobs/<?= urlencode($job->id) ?>/retry" class="inline">
                        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                        <button type="submit">Retry Job</button>
                    </form>
                <?php endif; ?>
                <?php if ($job->status === 'pending'): ?>
                    <form method="post" action="/dashboard/jobs/<?= urlencode($job->id) ?>/cancel" class="inline">
                        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                        <button type="submit">Cancel Job</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="grid">
            <div>
                <span class="label">Site</span>
                <div class="value"><?= htmlspecialchars($site?->label ?? $job->siteId) ?></div>
                <div class="muted"><?= htmlspecialchars($site?->url ?? $job->siteId) ?></div>
            </div>
            <div>
                <span class="label">Status</span>
                <div class="value"><?= htmlspecialchars($job->status) ?></div>
            </div>
            <div>
                <span class="label">Priority</span>
                <div class="value"><?= htmlspecialchars((string) $job->priority) ?></div>
            </div>
            <div>
                <span class="label">Attempts</span>
                <div class="value"><?= htmlspecialchars($job->attempts . ' / ' . $job->maxAttempts) ?></div>
            </div>
            <div>
                <span class="label">Scheduled</span>
                <div class="value"><?= htmlspecialchars($job->scheduledAt ?? 'Immediate') ?></div>
            </div>
            <div>
                <span class="label">Started</span>
                <div class="value"><?= htmlspecialchars($job->startedAt ?? 'Not started') ?></div>
            </div>
            <div>
                <span class="label">Completed</span>
                <div class="value"><?= htmlspecialchars($job->completedAt ?? 'Not completed') ?></div>
            </div>
            <div>
                <span class="label">Duration</span>
                <div class="value"><?= htmlspecialchars($job->durationMs !== null ? $job->durationMs . ' ms' : 'N/A') ?></div>
            </div>
        </div>
    </div>

    <div class="panel">
        <span class="label">Params</span>
        <pre><?= htmlspecialchars(json_encode($job->params ?? (object) [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
    </div>

    <div class="panel">
        <span class="label">Output</span>
        <pre><?= htmlspecialchars($job->output ?? 'No stdout captured.') ?></pre>
    </div>

    <div class="panel">
        <span class="label">Error Output</span>
        <pre><?= htmlspecialchars($job->errorOutput ?? 'No stderr captured.') ?></pre>
    </div>
</div>
</body>
</html>
