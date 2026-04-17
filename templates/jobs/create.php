<?php

declare(strict_types=1);

/** @var string $appName */
/** @var JetWP\Control\Auth\Csrf $csrf */
/** @var JetWP\Control\Models\User $user */
/** @var list<JetWP\Control\Models\Site> $sites */
/** @var list<string> $jobTypes */
/** @var array<string, mixed> $old */
/** @var array<string, array<int, string>> $errors */

$pageTitle = $appName . ' · Create Job';
$pageEyebrow = 'New Work';
$pageHeading = 'Create Job';
$pageLead = 'Supported MVP job types only. Params validated before queueing.';
$activeNav = 'create';
$pageHeaderAside = '<a class="btn ghost" href="/dashboard/jobs"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>Back to Jobs</a>';

require __DIR__ . '/../_chrome.php';
?>

<div class="panel glow">
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
                <label class="field" for="site_id">Site</label>
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
                <label class="field" for="type">Job type</label>
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
                <label class="field" for="priority">Priority</label>
                <input id="priority" name="priority" type="number" min="1" max="10" value="<?= htmlspecialchars((string) ($old['priority'] ?? '5')) ?>" required>
            </div>

            <div>
                <label class="field" for="scheduled_at">Schedule at</label>
                <input id="scheduled_at" name="scheduled_at" type="text" value="<?= htmlspecialchars((string) ($old['scheduled_at'] ?? '')) ?>" placeholder="2026-04-11 03:00:00">
            </div>
        </div>

        <div style="margin-top: 20px;">
            <label class="field" for="params_json">Params JSON object</label>
            <textarea id="params_json" name="params_json" spellcheck="false"><?= htmlspecialchars((string) ($old['params_json'] ?? '{}')) ?></textarea>
            <div class="hint">
                Examples: <code>{"slug":"woocommerce"}</code> for <code>plugin.update</code>, <code>{}</code> for <code>cache.flush</code> or <code>translations.update</code>, optional <code>{"version":"6.7.2"}</code> for <code>core.update</code>.
            </div>
        </div>

        <div class="divider"></div>
        <div class="row" style="justify-content: flex-end;">
            <a class="btn ghost" href="/dashboard/jobs">Cancel</a>
            <button type="submit">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg>
                Queue Job
            </button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../_chrome_end.php'; ?>
