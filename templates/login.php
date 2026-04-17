<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var array<string, string> $old */
/** @var string|null $error */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($appName) ?> · Sign in</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #e6edf7;
            --ink-dim: #b6c2d6;
            --muted: #7d8aa3;
            --line: rgba(148, 163, 184, 0.16);
            --accent: #7c5cff;
            --accent-2: #22d3ee;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--ink);
            background: #05070d;
            min-height: 100vh;
            display: grid;
            place-items: center;
            overflow: hidden;
            position: relative;
        }
        body::before {
            content: '';
            position: fixed; inset: -20%;
            background:
                radial-gradient(circle at 20% 30%, rgba(124,92,255,0.35), transparent 40%),
                radial-gradient(circle at 80% 20%, rgba(34,211,238,0.28), transparent 45%),
                radial-gradient(circle at 65% 85%, rgba(52,211,153,0.22), transparent 45%),
                radial-gradient(circle at 10% 80%, rgba(244,114,182,0.22), transparent 45%);
            filter: blur(10px);
            animation: drift 22s ease-in-out infinite alternate;
            z-index: 0;
        }
        body::after {
            content: '';
            position: fixed; inset: 0;
            background-image:
                linear-gradient(rgba(148,163,184,0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148,163,184,0.05) 1px, transparent 1px);
            background-size: 52px 52px;
            mask-image: radial-gradient(ellipse at center, #000 35%, transparent 80%);
            -webkit-mask-image: radial-gradient(ellipse at center, #000 35%, transparent 80%);
            z-index: 0;
        }
        @keyframes drift {
            0% { transform: translate3d(0,0,0) scale(1); }
            100% { transform: translate3d(-4%, 3%, 0) scale(1.08); }
        }

        .card {
            position: relative;
            z-index: 1;
            width: min(100%, 440px);
            margin: 24px;
            padding: 36px 34px 34px;
            background: rgba(17, 24, 42, 0.62);
            backdrop-filter: blur(20px) saturate(140%);
            -webkit-backdrop-filter: blur(20px) saturate(140%);
            border: 1px solid var(--line);
            border-radius: 22px;
            box-shadow: 0 40px 100px -20px rgba(5,7,13,0.9);
            overflow: hidden;
        }
        .card::before {
            content: ''; position: absolute; inset: -1px;
            border-radius: 22px; padding: 1px;
            background: linear-gradient(135deg, rgba(124,92,255,0.6), rgba(34,211,238,0.35), transparent 70%);
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor; mask-composite: exclude;
            pointer-events: none;
        }

        .brand { display: inline-flex; align-items: center; gap: 12px; margin-bottom: 22px; }
        .brand-mark {
            width: 38px; height: 38px; border-radius: 12px;
            background: linear-gradient(135deg, #7c5cff, #22d3ee, #34d399);
            box-shadow: 0 0 0 1px rgba(255,255,255,0.12) inset, 0 14px 30px -8px rgba(124,92,255,0.7);
            position: relative;
        }
        .brand-mark::after {
            content: ''; position: absolute; inset: 5px; border-radius: 8px;
            background: radial-gradient(circle at 30% 25%, rgba(255,255,255,0.55), transparent 55%);
        }
        .brand-text { font-family: 'Space Grotesk', sans-serif; font-weight: 700; letter-spacing: 0.02em; }
        .brand-text small { display: block; color: var(--muted); font-weight: 500; font-size: 10px; letter-spacing: 0.18em; text-transform: uppercase; margin-top: 2px; }

        h1 {
            margin: 0 0 6px;
            font: 700 1.8rem/1.15 'Space Grotesk', sans-serif;
            letter-spacing: -0.02em;
            background: linear-gradient(180deg, #fff, #b6c2d6);
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        .subtitle { color: var(--ink-dim); margin: 0 0 28px; font-size: 14px; }

        label {
            display: block; margin-bottom: 8px;
            color: var(--muted);
            font-size: 11px; font-weight: 600;
            letter-spacing: 0.14em; text-transform: uppercase;
        }
        input {
            width: 100%; margin-bottom: 18px;
            padding: 13px 15px;
            color: var(--ink);
            background: rgba(5,7,13,0.6);
            border: 1px solid var(--line);
            border-radius: 12px;
            font: 500 14px/1.4 'Inter', sans-serif;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        input:focus {
            outline: 0; border-color: rgba(124,92,255,0.6);
            box-shadow: 0 0 0 4px rgba(124,92,255,0.15);
            background: rgba(5,7,13,0.85);
        }
        button {
            appearance: none; border: 0; cursor: pointer;
            width: 100%; padding: 14px 18px;
            color: #fff;
            background: linear-gradient(135deg, #7c5cff, #5b3dff);
            border-radius: 12px;
            font: 700 14px/1 'Inter', sans-serif; letter-spacing: 0.02em;
            box-shadow: 0 14px 30px -10px rgba(124,92,255,0.8), 0 0 0 1px rgba(255,255,255,0.06) inset;
            transition: transform .15s, box-shadow .2s;
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
        }
        button:hover { transform: translateY(-1px); box-shadow: 0 18px 36px -10px rgba(124,92,255,0.9), 0 0 0 1px rgba(255,255,255,0.12) inset; }

        .error {
            margin-bottom: 18px;
            padding: 12px 14px;
            border: 1px solid rgba(248,113,113,0.35);
            border-radius: 12px;
            background: rgba(248,113,113,0.1);
            color: #fecaca;
            font-size: 13px;
        }

        .foot {
            margin-top: 22px; padding-top: 20px;
            border-top: 1px solid var(--line);
            display: flex; align-items: center; justify-content: space-between;
            color: var(--muted); font-size: 12px;
        }
        .dot-pulse { display: inline-flex; align-items: center; gap: 8px; }
        .dot-pulse::before {
            content: ''; width: 7px; height: 7px; border-radius: 50%;
            background: #34d399; box-shadow: 0 0 10px #34d399;
            animation: pulse 1.8s ease-in-out infinite;
        }
        @keyframes pulse {
            0%,100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.45; transform: scale(1.35); }
        }
    </style>
</head>
<body>
<main class="card">
    <div class="brand">
        <span class="brand-mark"></span>
        <span class="brand-text">JetWP<small>Control Plane</small></span>
    </div>

    <h1>Welcome back</h1>
    <p class="subtitle">Operator &amp; admin sign-in. Session secured with CSRF and rotated tokens.</p>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">

        <label for="username">Username</label>
        <input id="username" name="username" type="text" value="<?= htmlspecialchars($old['username'] ?? '') ?>" required autofocus autocomplete="username">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">

        <button type="submit">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            Sign In
        </button>
    </form>

    <div class="foot">
        <span class="dot-pulse">Systems nominal</span>
        <span><?= htmlspecialchars($appName) ?></span>
    </div>
</main>
</body>
</html>
