<?php

declare(strict_types=1);

use JetWP\Control\Queue\ConcurrencyManager;
use JetWP\Control\Queue\Worker;
use JetWP\Control\Runner\JobExecutor;
use JetWP\Control\Runner\SshClient;
use JetWP\Control\Services\ActivityLogService;
use JetWP\Control\Services\AlertService;

$app = require __DIR__ . '/bootstrap.php';

$db = $app['connection']->pdo();
$config = $app['config'];
$args = $_SERVER['argv'] ?? [];
$once = in_array('--once', $args, true);
$activityLog = new ActivityLogService($db);
$alerts = new AlertService($db, $config, $activityLog);

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
);

$processed = $worker->run($once);
fwrite(STDOUT, sprintf("Processed jobs: %d\n", $processed));
