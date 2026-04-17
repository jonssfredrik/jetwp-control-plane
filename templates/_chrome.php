<?php

declare(strict_types=1);

/**
 * Shared UI chrome for the Control Plane.
 *
 * Emits <head> with the design system, opens <body> and a main container.
 * Each template includes this partial after setting:
 *   $pageTitle    string  - Browser tab title
 *   $pageHeading  string  - Visible H1
 *   $pageLead     string  - One-line subtitle under the H1
 *   $activeNav    string  - One of: dashboard|sites|jobs|create
 *   $showLogout   bool    - Whether to show the logout button (default true)
 *   $csrf                 - Csrf instance for the logout form
 *   $appName      string
 */

$showLogout = $showLogout ?? true;
$activeNav = $activeNav ?? '';

$navItems = [
    ['key' => 'dashboard', 'label' => 'Overview',   'href' => '/dashboard',            'icon' => 'grid'],
    ['key' => 'sites',     'label' => 'Sites',      'href' => '/dashboard/sites',      'icon' => 'globe'],
    ['key' => 'jobs',      'label' => 'Jobs',       'href' => '/dashboard/jobs',       'icon' => 'bolt'],
    ['key' => 'create',    'label' => 'New Job',    'href' => '/dashboard/jobs/create','icon' => 'plus'],
];

$icons = [
    'grid'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>',
    'globe' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>',
    'bolt'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg>',
    'plus'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>',
    'logout'=> '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5M15 12H3"/></svg>',
    'spark' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8"/></svg>',
];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-0: #05070d;
            --bg-1: #0a0f1c;
            --bg-2: #0f172a;
            --panel: rgba(17, 24, 42, 0.55);
            --panel-solid: #111a2e;
            --panel-hi: rgba(30, 41, 66, 0.65);
            --line: rgba(148, 163, 184, 0.14);
            --line-hi: rgba(148, 163, 184, 0.28);
            --ink: #e6edf7;
            --ink-dim: #b6c2d6;
            --muted: #7d8aa3;
            --accent: #7c5cff;
            --accent-2: #22d3ee;
            --accent-3: #34d399;
            --warn: #f59e0b;
            --danger: #f87171;
            --grad: linear-gradient(135deg, #7c5cff 0%, #22d3ee 60%, #34d399 120%);
            --grad-soft: linear-gradient(135deg, rgba(124,92,255,0.25), rgba(34,211,238,0.18));
            --shadow-lg: 0 30px 80px -20px rgba(8, 12, 24, 0.8), 0 0 0 1px rgba(148,163,184,0.06);
            --radius: 18px;
        }

        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            color: var(--ink);
            background: var(--bg-0);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            font-size: 14px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
            position: relative;
            min-height: 100vh;
        }

        /* Animated aurora background */
        body::before {
            content: '';
            position: fixed;
            inset: -30% -10% -10% -10%;
            background:
                radial-gradient(circle at 18% 20%, rgba(124, 92, 255, 0.22), transparent 42%),
                radial-gradient(circle at 82% 15%, rgba(34, 211, 238, 0.18), transparent 40%),
                radial-gradient(circle at 70% 85%, rgba(52, 211, 153, 0.15), transparent 45%),
                radial-gradient(circle at 15% 90%, rgba(244, 114, 182, 0.14), transparent 40%);
            filter: blur(20px);
            animation: drift 28s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }
        /* Subtle grid overlay */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(148,163,184,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148,163,184,0.04) 1px, transparent 1px);
            background-size: 56px 56px;
            mask-image: radial-gradient(ellipse at top, #000 30%, transparent 75%);
            -webkit-mask-image: radial-gradient(ellipse at top, #000 30%, transparent 75%);
            pointer-events: none;
            z-index: 0;
        }

        @keyframes drift {
            0%   { transform: translate3d(0, 0, 0) scale(1); }
            50%  { transform: translate3d(-3%, 2%, 0) scale(1.05); }
            100% { transform: translate3d(2%, -2%, 0) scale(1.02); }
        }

        .wrap { position: relative; z-index: 1; max-width: 1280px; margin: 0 auto; padding: 28px 28px 64px; }

        /* ======= Top bar ======= */
        .topnav {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; padding: 12px 16px; margin-bottom: 28px;
            background: linear-gradient(180deg, rgba(17,24,42,0.75), rgba(17,24,42,0.45));
            backdrop-filter: blur(14px) saturate(140%);
            -webkit-backdrop-filter: blur(14px) saturate(140%);
            border: 1px solid var(--line);
            border-radius: 999px;
            box-shadow: var(--shadow-lg);
        }
        .brand {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 4px 10px 4px 6px;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700; letter-spacing: 0.02em;
            color: var(--ink);
            text-decoration: none;
        }
        .brand-mark {
            width: 30px; height: 30px; border-radius: 10px;
            background: var(--grad);
            box-shadow: 0 0 0 1px rgba(255,255,255,0.12) inset, 0 10px 24px -6px rgba(124,92,255,0.55);
            display: grid; place-items: center;
            position: relative;
        }
        .brand-mark::after {
            content: ''; position: absolute; inset: 4px; border-radius: 7px;
            background: radial-gradient(circle at 30% 25%, rgba(255,255,255,0.55), transparent 55%);
        }
        .brand small { color: var(--muted); font-weight: 500; letter-spacing: 0.14em; text-transform: uppercase; font-size: 10px; }

        .nav {
            display: flex; gap: 4px; padding: 4px;
            background: rgba(10,15,28,0.6);
            border: 1px solid var(--line);
            border-radius: 999px;
        }
        .nav a {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 14px; border-radius: 999px;
            color: var(--ink-dim); text-decoration: none;
            font-weight: 500; font-size: 13px;
            transition: all .18s ease;
            position: relative;
        }
        .nav a svg { width: 16px; height: 16px; opacity: 0.85; }
        .nav a:hover { color: var(--ink); background: rgba(124,92,255,0.08); }
        .nav a.active {
            color: #fff;
            background: linear-gradient(135deg, rgba(124,92,255,0.35), rgba(34,211,238,0.25));
            box-shadow: 0 0 0 1px rgba(124,92,255,0.45) inset, 0 8px 24px -8px rgba(124,92,255,0.55);
        }

        .topnav-right { display: flex; align-items: center; gap: 10px; }
        .userchip {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 6px 12px 6px 6px; border-radius: 999px;
            background: rgba(10,15,28,0.6); border: 1px solid var(--line);
            font-size: 12px; color: var(--ink-dim);
        }
        .avatar {
            width: 26px; height: 26px; border-radius: 50%;
            background: var(--grad); color: #fff;
            display: grid; place-items: center;
            font-weight: 700; font-size: 11px;
            box-shadow: 0 0 0 1px rgba(255,255,255,0.1) inset;
        }

        /* ======= Hero / Page header ======= */
        .hero {
            display: flex; align-items: flex-end; justify-content: space-between;
            gap: 24px; flex-wrap: wrap; margin-bottom: 28px;
        }
        .hero h1 {
            margin: 0 0 6px;
            font: 700 2.25rem/1.1 'Space Grotesk', sans-serif;
            letter-spacing: -0.02em;
            background: linear-gradient(180deg, #fff, #b6c2d6);
            -webkit-background-clip: text; background-clip: text;
            color: transparent;
        }
        .hero .eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 4px 10px; border-radius: 999px;
            background: rgba(124,92,255,0.12); border: 1px solid rgba(124,92,255,0.35);
            color: #c7bcff; font-size: 11px; font-weight: 600;
            letter-spacing: 0.14em; text-transform: uppercase;
            margin-bottom: 12px;
        }
        .hero .eyebrow::before {
            content: ''; width: 6px; height: 6px; border-radius: 50%;
            background: var(--accent-2);
            box-shadow: 0 0 10px var(--accent-2);
            animation: pulse 1.6s ease-in-out infinite;
        }
        .hero .lead { color: var(--ink-dim); margin: 0; max-width: 60ch; }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.45; transform: scale(1.35); }
        }

        /* ======= Panels ======= */
        .panel {
            background: var(--panel);
            backdrop-filter: blur(14px) saturate(140%);
            -webkit-backdrop-filter: blur(14px) saturate(140%);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }
        .panel::before {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(255,255,255,0.04), transparent 40%);
            pointer-events: none;
        }
        .panel.glow::after {
            content: ''; position: absolute; inset: -1px; border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, rgba(124,92,255,0.5), rgba(34,211,238,0.25), transparent 60%);
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor; mask-composite: exclude;
            pointer-events: none;
        }

        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
        .grid.cols-2 { grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); }

        .label {
            display: inline-flex; align-items: center; gap: 8px;
            color: var(--muted); font-size: 11px; font-weight: 600;
            letter-spacing: 0.14em; text-transform: uppercase;
            margin-bottom: 10px;
        }
        .value { font-size: 1.2rem; font-weight: 700; color: var(--ink); font-family: 'Space Grotesk', sans-serif; letter-spacing: -0.01em; word-break: break-word; }
        .muted { color: var(--muted); margin: 0; }

        /* ======= Buttons ======= */
        .btn, button {
            appearance: none; border: 0; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 16px; border-radius: 12px;
            font: 600 13px/1 'Inter', sans-serif; letter-spacing: 0.01em;
            text-decoration: none; color: #fff;
            background: linear-gradient(135deg, #7c5cff, #5b3dff);
            box-shadow: 0 10px 26px -10px rgba(124,92,255,0.7), 0 0 0 1px rgba(255,255,255,0.06) inset;
            transition: transform .15s ease, box-shadow .2s ease, background .2s ease;
        }
        .btn:hover, button:hover { transform: translateY(-1px); box-shadow: 0 14px 30px -10px rgba(124,92,255,0.8), 0 0 0 1px rgba(255,255,255,0.1) inset; }
        .btn:active, button:active { transform: translateY(0); }
        .btn.ghost, button.ghost {
            background: rgba(148,163,184,0.08);
            color: var(--ink);
            box-shadow: 0 0 0 1px var(--line) inset;
        }
        .btn.ghost:hover, button.ghost:hover {
            background: rgba(148,163,184,0.14);
            box-shadow: 0 0 0 1px var(--line-hi) inset;
        }
        .btn.danger, button.danger {
            background: linear-gradient(135deg, #f87171, #dc2626);
            box-shadow: 0 10px 26px -10px rgba(248,113,113,0.65), 0 0 0 1px rgba(255,255,255,0.06) inset;
        }
        .btn svg { width: 15px; height: 15px; }
        .btn.sm, button.sm { padding: 7px 12px; font-size: 12px; border-radius: 10px; }

        form.inline { display: inline; }

        /* ======= Forms ======= */
        label.field { display: block; color: var(--muted); font-size: 11px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; margin-bottom: 8px; }
        input, select, textarea {
            width: 100%;
            padding: 12px 14px;
            color: var(--ink);
            background: rgba(10,15,28,0.65);
            border: 1px solid var(--line);
            border-radius: 12px;
            font: 500 14px/1.4 'Inter', sans-serif;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        input:focus, select:focus, textarea:focus {
            outline: 0; border-color: rgba(124,92,255,0.6);
            box-shadow: 0 0 0 4px rgba(124,92,255,0.15);
            background: rgba(10,15,28,0.85);
        }
        textarea { font-family: 'JetBrains Mono', Consolas, monospace; font-size: 13px; min-height: 200px; resize: vertical; }
        select {
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237d8aa3' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 40px;
        }
        .hint { margin-top: 8px; color: var(--muted); font-size: 12px; }

        /* ======= Tables ======= */
        .table-wrap { overflow-x: auto; border-radius: 16px; border: 1px solid var(--line); background: rgba(10,15,28,0.4); backdrop-filter: blur(10px); }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            padding: 14px 16px;
            text-align: left;
            color: var(--muted);
            font-size: 11px; font-weight: 600;
            letter-spacing: 0.14em; text-transform: uppercase;
            background: rgba(10,15,28,0.6);
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }
        tbody td {
            padding: 16px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
            color: var(--ink-dim);
        }
        tbody tr { transition: background .15s ease; }
        tbody tr:hover { background: rgba(124,92,255,0.06); }
        tbody tr:last-child td { border-bottom: 0; }
        td a { color: var(--ink); text-decoration: none; font-weight: 600; border-bottom: 1px dashed rgba(148,163,184,0.4); }
        td a:hover { color: #fff; border-bottom-color: var(--accent-2); }

        /* ======= Chips / Badges ======= */
        .chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 600; letter-spacing: 0.04em;
            background: rgba(148,163,184,0.12); color: var(--ink-dim);
            border: 1px solid var(--line);
        }
        .chip .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        .chip.good, .status-completed { color: #6ee7b7; background: rgba(52,211,153,0.1); border-color: rgba(52,211,153,0.3); }
        .chip.warn, .status-pending   { color: #fcd34d; background: rgba(245,158,11,0.1); border-color: rgba(245,158,11,0.3); }
        .chip.bad,  .status-failed, .status-cancelled { color: #fca5a5; background: rgba(248,113,113,0.1); border-color: rgba(248,113,113,0.3); }
        .chip.info, .status-running   { color: #7dd3fc; background: rgba(34,211,238,0.1); border-color: rgba(34,211,238,0.3); }

        .chip.live .dot { box-shadow: 0 0 0 0 currentColor; animation: pulse 1.8s ease-in-out infinite; }

        /* ======= Code / pre ======= */
        code, pre { font-family: 'JetBrains Mono', Consolas, monospace; font-size: 12.5px; }
        code { color: #c4b5fd; }
        pre {
            margin: 0; padding: 16px; border-radius: 12px;
            background: rgba(5,7,13,0.75);
            border: 1px solid var(--line);
            color: #d1d8e6;
            overflow-x: auto; white-space: pre-wrap; word-break: break-word;
            max-height: 400px;
        }

        /* ======= Flash / error ======= */
        .flash {
            margin-bottom: 20px; padding: 14px 18px; border-radius: 14px;
            background: rgba(52,211,153,0.08); border: 1px solid rgba(52,211,153,0.3);
            color: #a7f3d0; display: flex; align-items: center; gap: 10px;
        }
        .flash.error { background: rgba(248,113,113,0.08); border-color: rgba(248,113,113,0.3); color: #fecaca; }
        .error-list { margin: 0 0 18px; padding: 14px 18px; border-radius: 14px; background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.3); color: #fecaca; }

        /* ======= Stack utility ======= */
        .stack { display: grid; gap: 6px; }
        .stack-lg { display: grid; gap: 20px; }
        .row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .spacer { flex: 1; }
        .divider { height: 1px; background: var(--line); margin: 20px 0; }

        @media (max-width: 760px) {
            .wrap { padding: 18px 14px 48px; }
            .topnav { border-radius: 18px; flex-wrap: wrap; }
            .nav { flex-wrap: wrap; }
            .hero h1 { font-size: 1.75rem; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <nav class="topnav">
        <a class="brand" href="/dashboard">
            <span class="brand-mark"></span>
            <span>JetWP<br><small>Control Plane</small></span>
        </a>
        <div class="nav">
            <?php foreach ($navItems as $item): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $activeNav === $item['key'] ? 'active' : '' ?>">
                    <?= $icons[$item['icon']] ?>
                    <span><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="topnav-right">
            <?php if (isset($user)): ?>
                <span class="userchip">
                    <span class="avatar"><?= htmlspecialchars(strtoupper(substr($user->username, 0, 1))) ?></span>
                    <span><?= htmlspecialchars($user->username) ?> · <span style="color:var(--muted)"><?= htmlspecialchars($user->role) ?></span></span>
                </span>
            <?php endif; ?>
            <?php if ($showLogout): ?>
                <form method="post" action="/logout" class="inline">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                    <button type="submit" class="ghost" title="Log out">
                        <?= $icons['logout'] ?>
                        <span>Log out</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </nav>

    <header class="hero">
        <div>
            <?php if (!empty($pageEyebrow)): ?>
                <div class="eyebrow"><?= htmlspecialchars($pageEyebrow) ?></div>
            <?php endif; ?>
            <h1><?= htmlspecialchars($pageHeading) ?></h1>
            <?php if (!empty($pageLead)): ?>
                <p class="lead"><?= $pageLead /* may contain chips */ ?></p>
            <?php endif; ?>
        </div>
        <?php if (!empty($pageHeaderAside)): ?>
            <div class="row"><?= $pageHeaderAside ?></div>
        <?php endif; ?>
    </header>
