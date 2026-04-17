<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */

$pageTitle = $appName . ' · Overview';
$pageEyebrow = 'System Online';
$pageHeading = 'Control Plane';
$pageLead = 'Mission-control for the fleet. Orchestrate sites, telemetry and job outcomes in real time.';
$activeNav = 'dashboard';

require __DIR__ . '/_chrome.php';
?>

<section class="stack-lg">
    <div class="grid">
        <article class="panel glow">
            <div class="label">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                Signed in as
            </div>
            <div class="value"><?= htmlspecialchars($user->username) ?></div>
            <p class="muted" style="margin-top:6px"><?= htmlspecialchars($user->email) ?></p>
            <div class="divider"></div>
            <span class="chip info live"><span class="dot"></span>Session active</span>
        </article>

        <article class="panel">
            <div class="label">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3 7 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z"/></svg>
                Role
            </div>
            <div class="value"><?= htmlspecialchars($user->role) ?></div>
            <p class="muted" style="margin-top:6px">RBAC hooks are ready for operator/admin separation.</p>
            <div class="divider"></div>
            <span class="chip good"><span class="dot"></span>Privileges verified</span>
        </article>

        <article class="panel">
            <div class="label">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg>
                Next slice
            </div>
            <div class="value">Pilot Readiness</div>
            <p class="muted" style="margin-top:6px">Use Sites and Jobs to verify real site state, telemetry and job outcomes during the internal pilot.</p>
            <div class="divider"></div>
            <span class="chip warn"><span class="dot"></span>In progress</span>
        </article>
    </div>

    <div class="panel">
        <div class="label">Quick actions</div>
        <div class="row" style="margin-top: 6px;">
            <a class="btn" href="/dashboard/sites">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
                Browse Sites
            </a>
            <a class="btn ghost" href="/dashboard/jobs">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg>
                View Jobs
            </a>
            <a class="btn ghost" href="/dashboard/jobs/create">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                Create Job
            </a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/_chrome_end.php'; ?>
