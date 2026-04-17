<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
/** @var JetWP\Control\Models\Job $job */
/** @var JetWP\Control\Models\Site|null $site */
/** @var array{type: string, message: string}|null $flash */

$pageTitle = $appName . ' · Job ' . $job->type;
$pageEyebrow = 'Job Detail';
$pageHeading = $job->type;
$pageLead = '<code style="color:var(--ink-dim)">' . htmlspecialchars($job->id) . '</code>';
$activeNav = 'jobs';

$aside = '<span class="chip status-' . htmlspecialchars($job->status) . ' ' . ($job->status === 'running' ? 'live' : '') . '"><span class="dot"></span>' . htmlspecialchars($job->status) . '</span>';
$aside .= '<a class="btn ghost" href="/dashboard/jobs">Back</a>';
$pageHeaderAside = $aside;

require __DIR__ . '/../_chrome.php';
?>

<?php if ($flash !== null): ?>
    <div class="flash <?= htmlspecialchars($flash['type']) ?>">
        <?= htmlspecialchars($flash['message']) ?>
    </div>
<?php endif; ?>

<section class="stack-lg">
    <?php if ($job->status === 'failed' || $job->status === 'pending'): ?>
    <div class="panel">
        <div class="row">
            <div class="stack">
                <div class="label">Operator actions</div>
                <p class="muted" style="margin:0;">Manage the job lifecycle.</p>
            </div>
            <div class="spacer"></div>
            <?php if ($job->status === 'failed'): ?>
                <form method="post" action="/dashboard/jobs/<?= urlencode($job->id) ?>/retry" class="inline">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                    <button type="submit">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 3v6h6"/></svg>
                        Retry Job
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($job->status === 'pending'): ?>
                <form method="post" action="/dashboard/jobs/<?= urlencode($job->id) ?>/cancel" class="inline">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf->token()) ?>">
                    <button type="submit" class="danger">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 8l8 8M16 8l-8 8"/></svg>
                        Cancel Job
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="grid">
        <article class="panel">
            <div class="label">Site</div>
            <div class="value"><?= htmlspecialchars($site?->label ?? $job->siteId) ?></div>
            <p class="muted" style="margin-top:6px"><?= htmlspecialchars($site?->url ?? $job->siteId) ?></p>
        </article>
        <article class="panel">
            <div class="label">Status</div>
            <div class="value"><?= htmlspecialchars($job->status) ?></div>
        </article>
        <article class="panel">
            <div class="label">Priority</div>
            <div class="value"><?= htmlspecialchars((string) $job->priority) ?></div>
        </article>
        <article class="panel">
            <div class="label">Attempts</div>
            <div class="value" style="font-family:'JetBrains Mono',monospace;"><?= htmlspecialchars($job->attempts . ' / ' . $job->maxAttempts) ?></div>
        </article>
        <article class="panel">
            <div class="label">Scheduled</div>
            <div class="value" style="font-family:'JetBrains Mono',monospace; font-size: 0.95rem;"><?= htmlspecialchars($job->scheduledAt ?? 'Immediate') ?></div>
        </article>
        <article class="panel">
            <div class="label">Started</div>
            <div class="value" style="font-family:'JetBrains Mono',monospace; font-size: 0.95rem;"><?= htmlspecialchars($job->startedAt ?? 'Not started') ?></div>
        </article>
        <article class="panel">
            <div class="label">Completed</div>
            <div class="value" style="font-family:'JetBrains Mono',monospace; font-size: 0.95rem;"><?= htmlspecialchars($job->completedAt ?? 'Not completed') ?></div>
        </article>
        <article class="panel">
            <div class="label">Duration</div>
            <div class="value"><?= htmlspecialchars($job->durationMs !== null ? $job->durationMs . ' ms' : 'N/A') ?></div>
        </article>
    </div>

    <article class="panel">
        <div class="label">Params</div>
        <pre><?= htmlspecialchars(json_encode($job->params ?? (object) [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}') ?></pre>
    </article>

    <div class="grid cols-2">
        <article class="panel">
            <div class="label">Output</div>
            <pre><?= htmlspecialchars($job->output ?? 'No stdout captured.') ?></pre>
        </article>
        <article class="panel">
            <div class="label">Error Output</div>
            <pre><?= htmlspecialchars($job->errorOutput ?? 'No stderr captured.') ?></pre>
        </article>
    </div>
</section>

<?php require __DIR__ . '/../_chrome_end.php'; ?>
