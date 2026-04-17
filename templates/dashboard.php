<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?></title>
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
            background:
                linear-gradient(180deg, rgba(24, 78, 58, 0.08), transparent 220px),
                var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", sans-serif;
        }

        .wrap {
            max-width: 960px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        h1 {
            margin: 0 0 6px;
            font: 700 2rem/1.1 Georgia, serif;
        }

        .muted {
            color: var(--muted);
            margin: 0;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 10px 28px rgba(17, 32, 21, 0.05);
        }

        .label {
            display: block;
            margin-bottom: 10px;
            color: var(--muted);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .value {
            font-size: 1.2rem;
            font-weight: 700;
        }

        button {
            padding: 10px 16px;
            border: 0;
            border-radius: 999px;
            background: var(--accent);
            color: #fff;
            cursor: pointer;
            font-weight: 700;
        }
        .button-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border-radius: 999px;
            background: #e8efe8;
            color: var(--ink);
            font-weight: 700;
            text-decoration: none;
        }
        .actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <h1><?= htmlspecialchars($appName) ?></h1>
            <p class="muted">Bootstrap complete. This is the initial authenticated dashboard shell.</p>
        </div>

        <form method="post" action="/logout">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
            <button type="submit">Log Out</button>
        </form>
    </header>

    <div class="actions" style="margin-bottom: 24px;">
        <a class="button-link" href="/dashboard/sites">Sites</a>
        <a class="button-link" href="/dashboard/jobs">Jobs</a>
        <a class="button-link" href="/dashboard/jobs/create">Create Job</a>
    </div>

    <section class="grid">
        <article class="panel">
            <span class="label">Signed in as</span>
            <div class="value"><?= htmlspecialchars($user->username) ?></div>
            <p class="muted"><?= htmlspecialchars($user->email) ?></p>
        </article>

        <article class="panel">
            <span class="label">Role</span>
            <div class="value"><?= htmlspecialchars($user->role) ?></div>
            <p class="muted">RBAC hooks are ready for operator/admin separation.</p>
        </article>

        <article class="panel">
            <span class="label">Next slice</span>
            <div class="value">Pilot Readiness</div>
            <p class="muted">Use Sites and Jobs to verify real site state, telemetry and job outcomes during the internal pilot.</p>
        </article>
    </section>
</div>
</body>
</html>
