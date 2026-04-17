<?php

declare(strict_types=1);

use JetWP\Control\Api\AgentApi;
use JetWP\Control\Api\DashboardJobsApi;
use JetWP\Control\Api\CreateJobController;
use JetWP\Control\Auth\Authorization;
use JetWP\Control\Auth\AuthorizationException;
use JetWP\Control\Jobs\JobFactory;
use JetWP\Control\Jobs\JobValidationException;
use JetWP\Control\Jobs\JobValidator;
use JetWP\Control\Models\Job;
use JetWP\Control\Models\Server;
use JetWP\Control\Models\Site;
use JetWP\Control\Models\Telemetry;
use JetWP\Control\Models\User;
use JetWP\Control\Services\SiteInventoryService;

$app = require dirname(__DIR__) . '/bootstrap.php';

$auth = $app['auth'];
$csrf = $app['csrf'];
$config = $app['config'];
$db = $app['db'];
$secrets = $app['secrets'];
$activityLog = $app['activity_log'];

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$render = static function (string $template, array $data = []) use ($config): void {
    extract($data, EXTR_SKIP);
    $appName = $config->get('app.name', 'JetWP Control Plane');
    require JETWP_CONTROL_ROOT . '/templates/' . $template . '.php';
};
$authorization = new Authorization();
$siteInventoryService = new SiteInventoryService($db);

$agentApi = new AgentApi($db, $secrets, $activityLog);
if ($agentApi->handles($path)) {
    $agentApi->dispatch($method, $path);
    return;
}

$dashboardApiUser = $auth?->user();
$dashboardJobsApi = new DashboardJobsApi(
    $db,
    $csrf,
    $dashboardApiUser instanceof User ? $dashboardApiUser : null,
    $activityLog
);
if ($dashboardJobsApi->handles($path)) {
    $dashboardJobsApi->dispatch($method, $path);
    return;
}

if ($path === '/login' && $method === 'POST') {
    if (!$csrf->validate($_POST['_token'] ?? null)) {
        http_response_code(419);
        $render('login', [
            'error' => 'Invalid CSRF token. Refresh the page and try again.',
            'csrf' => $csrf,
            'old' => ['username' => (string) ($_POST['username'] ?? '')],
        ]);
        return;
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($auth->attempt($username, $password)) {
        header('Location: /dashboard');
        exit;
    }

    http_response_code(422);
    $render('login', [
        'error' => 'Invalid username or password.',
        'csrf' => $csrf,
        'old' => ['username' => $username],
    ]);
    return;
}

if ($path === '/logout' && $method === 'POST') {
    if (!$csrf->validate($_POST['_token'] ?? null)) {
        http_response_code(419);
        echo 'Invalid CSRF token.';
        return;
    }

    $auth->logout();
    header('Location: /login');
    exit;
}

if ($path === '/login') {
    $render('login', ['csrf' => $csrf, 'old' => ['username' => '']]);
    return;
}

if (!$auth->check()) {
    header('Location: /login');
    exit;
}

$user = $auth->user();
if (!$user instanceof User) {
    $auth->logout();
    header('Location: /login');
    exit;
}

if (is_admin_area_path($path)) {
    try {
        $authorization->ensureAdmin($user);
    } catch (AuthorizationException) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }
}

if ($path === '/' || $path === '/dashboard') {
    $render('dashboard', [
        'csrf' => $csrf,
        'user' => $user,
    ]);
    return;
}

if ($path === '/dashboard/sites' && $method === 'GET') {
    $authorization->ensureJobsAccess($user);
    $sites = Site::all($db);
    $serverLabels = [];
    foreach (Server::all($db) as $server) {
        $serverLabels[$server->id] = $server->label . ' (' . $server->hostname . ')';
    }

    $latestTelemetry = [];
    $recentJobs = [];
    foreach ($sites as $site) {
        $latestTelemetry[$site->id] = Telemetry::latestForSite($db, $site->id);
        $recentJobs[$site->id] = Job::list($db, ['site_id' => $site->id], 3);
    }

    $render('sites/index', [
        'csrf' => $csrf,
        'user' => $user,
        'sites' => $sites,
        'serverLabels' => $serverLabels,
        'latestTelemetry' => $latestTelemetry,
        'recentJobs' => $recentJobs,
        'heartbeatThresholdMinutes' => (int) $config->get('alerts.heartbeat_missed_minutes', 30),
    ]);
    return;
}

if ($method === 'GET' && preg_match('#^/dashboard/sites/([a-f0-9-]{36})$#i', $path, $matches) === 1) {
    $authorization->ensureJobsAccess($user);
    $site = Site::findById($db, strtolower($matches[1]));
    if (!$site instanceof Site) {
        http_response_code(404);
        echo 'Site not found.';
        return;
    }

    $inventory = $siteInventoryService->snapshotForSite($site);

    $render('sites/show', [
        'csrf' => $csrf,
        'user' => $user,
        'site' => $site,
        'server' => Server::findById($db, $site->serverId),
        'latestTelemetry' => $inventory['latest_telemetry'],
        'coreInventory' => $inventory['core'],
        'pluginInventory' => $inventory['plugins'],
        'themeInventory' => $inventory['themes'],
        'inventorySummary' => $inventory['summary'],
        'recentJobs' => Job::list($db, ['site_id' => $site->id], 10),
        'agentActivity' => $activityLog?->recentForSite($site->id, 50, 'agent.') ?? [],
        'heartbeatThresholdMinutes' => (int) $config->get('alerts.heartbeat_missed_minutes', 30),
        'flash' => pull_flash('flash'),
    ]);
    return;
}

if ($method === 'POST' && preg_match('#^/dashboard/sites/([a-f0-9-]{36})/actions$#i', $path, $matches) === 1) {
    $authorization->ensureJobsAccess($user);
    if (!$csrf->validate($_POST['_token'] ?? null)) {
        http_response_code(419);
        echo 'Invalid CSRF token.';
        return;
    }

    $site = Site::findById($db, strtolower($matches[1]));
    if (!$site instanceof Site) {
        http_response_code(404);
        echo 'Site not found.';
        return;
    }

    $inventory = $siteInventoryService->snapshotForSite($site);
    $actionType = trim((string) ($_POST['action_type'] ?? ''));
    $controller = new CreateJobController(new JobValidator($db), new JobFactory($db));
    $createdJobs = [];
    $skipped = [];

    try {
        $authorization->ensureJobTypeAllowed($user, $actionType);

        if ($actionType === 'core.update') {
            $params = [];
            if (($inventory['core']['available_update'] ?? null) !== null) {
                $params['version'] = $inventory['core']['available_update'];
            }

            $job = $controller->handle([
                'site_id' => $site->id,
                'type' => 'core.update',
                'priority' => 5,
                'params' => $params,
            ]);
            $createdJobs[] = $job;
        } elseif ($actionType === 'core.rollback') {
            $rollbackVersion = $inventory['core']['rollback_version'] ?? null;
            if (!is_string($rollbackVersion) || trim($rollbackVersion) === '') {
                throw new InvalidArgumentException('No rollback version is available for WordPress core on this site yet.');
            }

            $job = $controller->handle([
                'site_id' => $site->id,
                'type' => 'core.rollback',
                'priority' => 5,
                'params' => ['version' => $rollbackVersion],
            ]);
            $createdJobs[] = $job;
        } elseif (in_array($actionType, ['plugin.update', 'plugin.rollback', 'theme.update', 'theme.rollback'], true)) {
            $selectedSlugs = normalize_selected_slugs($_POST['slugs'] ?? []);
            if ($selectedSlugs === []) {
                throw new InvalidArgumentException('Select at least one item before running a bulk or row action.');
            }

            $items = str_starts_with($actionType, 'plugin.')
                ? index_inventory_by_slug($inventory['plugins'])
                : index_inventory_by_slug($inventory['themes']);

            foreach ($selectedSlugs as $slug) {
                if (!isset($items[$slug])) {
                    $skipped[] = $slug . ' (not present in current telemetry)';
                    continue;
                }

                $params = ['slug' => $slug];
                if (str_ends_with($actionType, '.rollback')) {
                    $rollbackVersion = $items[$slug]['rollback_version'] ?? null;
                    if (!is_string($rollbackVersion) || trim($rollbackVersion) === '') {
                        $skipped[] = $slug . ' (no rollback history)';
                        continue;
                    }

                    $params['version'] = $rollbackVersion;
                } elseif (($items[$slug]['update_available'] ?? null) === null) {
                    $skipped[] = $slug . ' (no update available)';
                    continue;
                }

                $createdJobs[] = $controller->handle([
                    'site_id' => $site->id,
                    'type' => $actionType,
                    'priority' => 5,
                    'params' => $params,
                ]);
            }
        } else {
            throw new InvalidArgumentException('Requested site action is not supported.');
        }
    } catch (AuthorizationException | JobValidationException | InvalidArgumentException $exception) {
        push_flash('flash', ['type' => 'error', 'message' => $exception->getMessage()]);
        header('Location: /dashboard/sites/' . urlencode($site->id));
        exit;
    }

    foreach ($createdJobs as $job) {
        $activityLog?->logJobCreated($job, $user, 'dashboard.site_actions', [
            'action_type' => $actionType,
        ]);
    }

    if ($createdJobs === []) {
        $message = 'No jobs were queued.';
        if ($skipped !== []) {
            $message .= ' Skipped: ' . implode(', ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? ', ...' : '');
        }
        push_flash('flash', ['type' => 'error', 'message' => $message]);
    } else {
        $message = sprintf(
            'Queued %d %s.',
            count($createdJobs),
            count($createdJobs) === 1 ? 'job' : 'jobs'
        );
        if ($skipped !== []) {
            $message .= ' Skipped: ' . implode(', ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? ', ...' : '');
        }
        push_flash('flash', ['type' => 'success', 'message' => $message]);
    }

    header('Location: /dashboard/sites/' . urlencode($site->id));
    exit;
}

if ($path === '/dashboard/jobs' && $method === 'GET') {
    $authorization->ensureJobsAccess($user);
    $filters = [
        'status' => trim((string) ($_GET['status'] ?? '')),
        'type' => trim((string) ($_GET['type'] ?? '')),
        'site_id' => trim((string) ($_GET['site_id'] ?? '')),
    ];
    $jobs = Job::list($db, $filters);
    $siteLabels = [];
    foreach (Site::all($db) as $site) {
        $siteLabels[$site->id] = $site->label . ' (' . $site->url . ')';
    }

    $render('jobs/index', [
        'csrf' => $csrf,
        'user' => $user,
        'jobs' => $jobs,
        'siteLabels' => $siteLabels,
        'filters' => $filters,
        'flash' => pull_flash('flash'),
    ]);
    return;
}

if ($path === '/dashboard/jobs/create' && $method === 'GET') {
    $authorization->ensureJobsAccess($user);
    $render('jobs/create', [
        'csrf' => $csrf,
        'user' => $user,
        'sites' => Site::all($db),
        'jobTypes' => available_job_types_for_user($user),
        'old' => pull_flash('job_form_old') ?? ['priority' => '5', 'params_json' => '{}'],
        'errors' => pull_flash('job_form_errors') ?? [],
    ]);
    return;
}

if ($path === '/dashboard/jobs' && $method === 'POST') {
    $authorization->ensureJobsAccess($user);
    if (!$csrf->validate($_POST['_token'] ?? null)) {
        http_response_code(419);
        echo 'Invalid CSRF token.';
        return;
    }

    $old = [
        'site_id' => trim((string) ($_POST['site_id'] ?? '')),
        'type' => trim((string) ($_POST['type'] ?? '')),
        'priority' => trim((string) ($_POST['priority'] ?? '5')),
        'scheduled_at' => trim((string) ($_POST['scheduled_at'] ?? '')),
        'params_json' => (string) ($_POST['params_json'] ?? '{}'),
    ];
    $errors = [];

    $params = decode_job_params_json($old['params_json'], $errors);

    if ($errors !== []) {
        push_flash('job_form_old', $old);
        push_flash('job_form_errors', $errors);
        header('Location: /dashboard/jobs/create');
        exit;
    }

    $controller = new CreateJobController(new JobValidator($db), new JobFactory($db));

    try {
        $authorization->ensureJobTypeAllowed($user, $old['type']);
        $job = $controller->handle([
            'site_id' => $old['site_id'],
            'type' => $old['type'],
            'priority' => $old['priority'],
            'scheduled_at' => $old['scheduled_at'] !== '' ? $old['scheduled_at'] : null,
            'params' => $params,
        ]);
    } catch (AuthorizationException $exception) {
        push_flash('job_form_old', $old);
        push_flash('job_form_errors', ['type' => [$exception->getMessage()]]);
        header('Location: /dashboard/jobs/create');
        exit;
    } catch (JobValidationException $exception) {
        push_flash('job_form_old', $old);
        push_flash('job_form_errors', $exception->errors);
        header('Location: /dashboard/jobs/create');
        exit;
    }

    $activityLog?->logJobCreated($job, $user, 'dashboard.ui');

    push_flash('flash', ['type' => 'success', 'message' => 'Job created successfully.']);
    header('Location: /dashboard/jobs/' . urlencode($job->id));
    exit;
}

if ($method === 'POST' && preg_match('#^/dashboard/jobs/([a-f0-9-]{36})/retry$#i', $path, $matches) === 1) {
    $authorization->ensureJobsAccess($user);
    if (!$csrf->validate($_POST['_token'] ?? null)) {
        http_response_code(419);
        echo 'Invalid CSRF token.';
        return;
    }

    $job = Job::findById($db, strtolower($matches[1]));
    if (!$job instanceof Job) {
        http_response_code(404);
        echo 'Job not found.';
        return;
    }

    try {
        $authorization->ensureJobTypeAllowed($user, $job->type);
        $retried = (new JobFactory($db))->retry($job);
    } catch (AuthorizationException $exception) {
        push_flash('flash', ['type' => 'error', 'message' => $exception->getMessage()]);
        header('Location: /dashboard/jobs/' . urlencode($job->id));
        exit;
    } catch (InvalidArgumentException $exception) {
        push_flash('flash', ['type' => 'error', 'message' => $exception->getMessage()]);
        header('Location: /dashboard/jobs/' . urlencode($job->id));
        exit;
    }

    $activityLog?->logJobCreated($retried, $user, 'dashboard.ui.retry', [
        'parent_job_id' => $job->id,
    ]);

    push_flash('flash', ['type' => 'success', 'message' => 'Job retried as a new pending job.']);
    header('Location: /dashboard/jobs/' . urlencode($retried->id));
    exit;
}

if ($method === 'POST' && preg_match('#^/dashboard/jobs/([a-f0-9-]{36})/cancel$#i', $path, $matches) === 1) {
    $authorization->ensureJobsAccess($user);
    if (!$csrf->validate($_POST['_token'] ?? null)) {
        http_response_code(419);
        echo 'Invalid CSRF token.';
        return;
    }

    try {
        $job = Job::cancel($db, strtolower($matches[1]));
    } catch (InvalidArgumentException $exception) {
        push_flash('flash', ['type' => 'error', 'message' => $exception->getMessage()]);
        header('Location: /dashboard/jobs/' . urlencode(strtolower($matches[1])));
        exit;
    }

    if (!$job instanceof Job) {
        http_response_code(404);
        echo 'Job not found.';
        return;
    }

    push_flash('flash', ['type' => 'success', 'message' => 'Job cancelled.']);
    header('Location: /dashboard/jobs/' . urlencode($job->id));
    exit;
}

if ($method === 'GET' && preg_match('#^/dashboard/jobs/([a-f0-9-]{36})$#i', $path, $matches) === 1) {
    $authorization->ensureJobsAccess($user);
    $job = Job::findById($db, strtolower($matches[1]));
    if (!$job instanceof Job) {
        http_response_code(404);
        echo 'Job not found.';
        return;
    }

    $render('jobs/show', [
        'csrf' => $csrf,
        'user' => $user,
        'job' => $job,
        'site' => Site::findById($db, $job->siteId),
        'jobActivity' => $activityLog?->recentForJob($job->id, 25) ?? [],
        'flash' => pull_flash('flash'),
    ]);
    return;
}

http_response_code(404);
echo 'Not found';

function push_flash(string $key, mixed $value): void
{
    $_SESSION[$key] = $value;
}

function pull_flash(string $key): mixed
{
    if (!array_key_exists($key, $_SESSION)) {
        return null;
    }

    $value = $_SESSION[$key];
    unset($_SESSION[$key]);

    return $value;
}

/**
 * @param array<string, array<int, string>> $errors
 */
function decode_job_params_json(string $json, array &$errors): array
{
    $trimmed = trim($json);
    if ($trimmed === '') {
        return [];
    }

    $decoded = json_decode($trimmed, true);
    if (!is_array($decoded) || ($trimmed === '[]') || (array_is_list($decoded) && $decoded !== [])) {
        $errors['params'][] = 'Params JSON must be a JSON object.';
        return [];
    }

    return $decoded;
}

function is_admin_area_path(string $path): bool
{
    foreach (['/dashboard/servers', '/dashboard/users', '/dashboard/settings'] as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function normalize_selected_slugs(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $item) {
        if (!is_string($item)) {
            continue;
        }

        $slug = trim($item);
        if ($slug === '') {
            continue;
        }

        $normalized[$slug] = $slug;
    }

    return array_values($normalized);
}

/**
 * @param list<array{slug:string}> $items
 * @return array<string, array<string, mixed>>
 */
function index_inventory_by_slug(array $items): array
{
    $indexed = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $slug = trim((string) ($item['slug'] ?? ''));
        if ($slug === '') {
            continue;
        }

        $indexed[$slug] = $item;
    }

    return $indexed;
}

/**
 * @return list<string>
 */
function available_job_types_for_user(User $user): array
{
    return JobValidator::supportedTypes();
}
