<?php

declare(strict_types=1);

use JetWP\Control\Auth\Auth;
use JetWP\Control\Auth\Csrf;
use JetWP\Control\Config\Config;
use JetWP\Control\Db\Connection;
use JetWP\Control\Db\Migrator;
use JetWP\Control\Security\Secrets;
use JetWP\Control\Services\ActivityLogService;
use JetWP\Control\Services\AlertService;

if (!defined('JETWP_CONTROL_ROOT')) {
    define('JETWP_CONTROL_ROOT', __DIR__);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'JetWP\\Control\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = JETWP_CONTROL_ROOT . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

$config = Config::load(
    JETWP_CONTROL_ROOT . '/config.php',
    JETWP_CONTROL_ROOT . '/config.example.php'
);

date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string) $config->get('session.name', 'jetwp_session'));
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (bool) $config->get('session.secure', false),
    ]);
    session_start();
}

$connection = new Connection($config);

$services = [
    'config' => $config,
    'connection' => $connection,
    'migrator' => new Migrator($connection, JETWP_CONTROL_ROOT . '/migrations'),
    'csrf' => new Csrf(),
    'secrets' => new Secrets((string) $config->get('app.encryption_key')),
];

if (PHP_SAPI !== 'cli') {
    $pdo = $connection->pdo();
    $activityLog = new ActivityLogService($pdo);
    $services['db'] = $pdo;
    $services['auth'] = new Auth($pdo, $activityLog);
    $services['activity_log'] = $activityLog;
    $services['alerts'] = new AlertService($pdo, $config, $activityLog);
} else {
    $pdo = $connection->pdo();
    $activityLog = new ActivityLogService($pdo);
    $services['db'] = $pdo;
    $services['auth'] = null;
    $services['activity_log'] = $activityLog;
    $services['alerts'] = new AlertService($pdo, $config, $activityLog);
}

return $services;
