<?php

declare(strict_types=1);

use JetWP\Control\Queue\ConcurrencyManager;
use JetWP\Control\Queue\Worker;
use JetWP\Control\Runner\JobExecutor;
use JetWP\Control\Runner\SshClient;
use JetWP\Control\Services\ActivityLogService;
use JetWP\Control\Services\AlertService;
use JetWP\Control\Support\DebugLogger;

$app = require __DIR__ . '/bootstrap.php';

$db = $app['connection']->pdo();
$config = $app['config'];
$args = $_SERVER['argv'] ?? [];
$once = in_array('--once', $args, true);
$activityLog = new ActivityLogService($db);
$alerts = new AlertService($db, $config, $activityLog);
$debugLogger = new DebugLogger(
    (bool) $config->get('queue.debug_log_enabled', true),
    (string) $config->get('queue.debug_log_path', JETWP_CONTROL_ROOT . '/storage/logs/queue-worker.log')
);

$debugLogger->info('Queue worker booting.', [
    'pid' => getmypid(),
    'once' => $once,
]);

$worker = new Worker(
    $db,
    new JobExecutor(
        $db,
        new SshClient(),
        (int) $config->get('queue.default_timeout', 300),
        $activityLog
    ),
    new ConcurrencyManager($db),
    (int) $config->get('queue.poll_interval', 5),
    (int) $config->get('queue.default_timeout', 300),
    $alerts,
    $activityLog,
    $debugLogger,
);

try {
    $processed = $worker->run($once);
    $debugLogger->info('Queue worker stopped cleanly.', [
        'pid' => getmypid(),
        'processed_jobs' => $processed,
    ]);
    fwrite(STDOUT, sprintf("Processed jobs: %d\n", $processed));
} catch (Throwable $exception) {
    $debugLogger->error('Queue worker crashed.', [
        'pid' => getmypid(),
        'exception_class' => $exception::class,
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);
    throw $exception;
}
