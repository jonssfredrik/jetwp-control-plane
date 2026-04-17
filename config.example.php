<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'JetWP Control Plane',
        'env' => getenv('JETWP_ENV') ?: 'local',
        'debug' => filter_var(getenv('JETWP_DEBUG') ?: '1', FILTER_VALIDATE_BOOL),
        'base_url' => getenv('JETWP_BASE_URL') ?: 'http://localhost:8080',
        'timezone' => getenv('JETWP_TIMEZONE') ?: 'Europe/Stockholm',
        'encryption_key' => getenv('JETWP_ENCRYPTION_KEY') ?: 'change-this-64-char-encryption-key-before-production-use',
    ],
    'db' => [
        'driver' => 'mysql',
        'host' => getenv('JETWP_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('JETWP_DB_PORT') ?: 3306),
        'database' => getenv('JETWP_DB_DATABASE') ?: 'jetwp',
        'username' => getenv('JETWP_DB_USERNAME') ?: 'root',
        'password' => getenv('JETWP_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],
    'session' => [
        'name' => getenv('JETWP_SESSION_NAME') ?: 'jetwp_session',
        'secure' => filter_var(getenv('JETWP_SESSION_SECURE') ?: '0', FILTER_VALIDATE_BOOL),
    ],
    'queue' => [
        'poll_interval' => (int) (getenv('JETWP_QUEUE_POLL_INTERVAL') ?: 5),
        'default_timeout' => (int) (getenv('JETWP_QUEUE_DEFAULT_TIMEOUT') ?: 300),
        'max_retries' => (int) (getenv('JETWP_QUEUE_MAX_RETRIES') ?: 3),
    ],
    'alerts' => [
        'enabled' => filter_var(getenv('JETWP_ALERTS_ENABLED') ?: '1', FILTER_VALIDATE_BOOL),
        'driver' => getenv('JETWP_ALERT_DRIVER') ?: 'log',
        'from_email' => getenv('JETWP_ALERT_FROM_EMAIL') ?: 'alerts@localhost',
        'from_name' => getenv('JETWP_ALERT_FROM_NAME') ?: 'JetWP Control Plane',
        'recipients' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', getenv('JETWP_ALERT_RECIPIENTS') ?: '')
        ))),
        'heartbeat_missed_minutes' => (int) (getenv('JETWP_ALERT_HEARTBEAT_MISSED_MINUTES') ?: 30),
    ],
    'security' => [
        'bcrypt_cost' => 12,
    ],
];
