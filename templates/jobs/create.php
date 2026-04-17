<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
/** @var list<JetWP\Control\Models\Site> $sites */
/** @var list<string> $jobTypes */
/** @var array<string, mixed> $old */
/** @var array<string, array<int, string>> $errors */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?> Create Job</title>
    <style>
        :root {
            --bg: #f5f7f2;
            --panel: #ffffff;
            --ink: #112015;
            --muted: #4d5d53;
            --accent: #184e3a;
            --line: #dfe7de;
            --error: #9f2a2a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: linear-gradient(180deg, rgba(24, 78, 58, 0.08), transparent 220px), var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", sans-serif;
        }
        .wrap {
            max-width: 920px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }
        .topbar, .actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
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
            padding: 24px;
            box-shadow: 0 10px 28px rgba(17, 32, 21, 0.05);
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }
        label {
            display: block;
            font-size: 0.9rem;
            color: var(--muted);
            margin-bottom: 6px;
        }
        select, input, textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            font: inherit;
        }
        textarea { min-height: 180px; resize: vertical; font-family: Consolas, monospace; }
        .error-list {
            margin: 0 0 16px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid #efc7c7;
            background: #fdeaea;
            color: var(--error);
        }
        .hint {
            margin-top: 8px;
            color: var(--muted);
            font-size: 0.92rem;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div>
            <h1>Create Job</h1>
            <p class="muted">Signed in as <?= htmlspecialchars($user->username) ?>. Supported MVP job types only.</p>
        </div>
        <div class="actions">
            <a class="button secondary" href="/dashboard/jobs">Back to Jobs</a>
            <form method="post" action="/logout">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                <button type="submit">Log Out</button>
            </form>
        </div>
    </div>

    <div class="panel">
        <?php if ($errors !== []): ?>
            <div class="error-list">
                <?php foreach ($errors as $messages): ?>
                    <?php foreach ($messages as $message): ?>
                        <div><?= htmlspecialchars($message) ?></div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/dashboard/jobs">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">

            <div class="grid">
                <div>
                    <label for="site_id">Site</label>
                    <select id="site_id" name="site_id" required>
                        <option value="">Select site</option>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?= htmlspecialchars($site->id) ?>" <?= ($old['site_id'] ?? '') === $site->id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($site->label . ' (' . $site->url . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="type">Job type</label>
                    <select id="type" name="type" required>
                        <option value="">Select job type</option>
                        <?php foreach ($jobTypes as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>" <?= ($old['type'] ?? '') === $type ? 'selected' : '' ?>>
                                <?= htmlspecialchars($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="priority">Priority</label>
                    <input id="priority" name="priority" type="number" min="1" max="10" value="<?= htmlspecialchars((string) ($old['priority'] ?? '5')) ?>" required>
                </div>

                <div>
                    <label for="scheduled_at">Schedule at</label>
                    <input id="scheduled_at" name="scheduled_at" type="text" value="<?= htmlspecialchars((string) ($old['scheduled_at'] ?? '')) ?>" placeholder="2026-04-11 03:00:00">
                </div>
            </div>

            <div style="margin-top: 16px;">
                <label for="params_json">Params JSON object</label>
                <textarea id="params_json" name="params_json" spellcheck="false"><?= htmlspecialchars((string) ($old['params_json'] ?? '{}')) ?></textarea>
                <div class="hint">
                    Examples: `{"slug":"woocommerce"}` for `plugin.update`, `{}` for `cache.flush` or `translations.update`, optional `{"version":"6.7.2"}` for `core.update`.
                </div>
            </div>

            <div class="actions" style="margin-top: 20px;">
                <a class="button secondary" href="/dashboard/jobs">Cancel</a>
                <button type="submit">Create Job</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
