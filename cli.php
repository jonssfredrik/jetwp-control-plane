<?php

declare(strict_types=1);

use JetWP\Control\Models\User;
use JetWP\Control\Models\Server;
use JetWP\Control\Models\Job;
use JetWP\Control\Models\PairingToken;
use JetWP\Control\Runner\JobExecutor;
use JetWP\Control\Runner\SshClient;
use JetWP\Control\Services\ActivityLogService;
use JetWP\Control\Services\AlertService;

$app = require __DIR__ . '/bootstrap.php';

$argv = $_SERVER['argv'] ?? [];
$command = $argv[1] ?? null;

if ($command === null) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php cli.php migrate\n");
    fwrite(STDERR, "  php cli.php migrate:fresh\n");
    fwrite(STDERR, "  php cli.php user:create <username> <email> [--role=admin|operator] [--password=secret]\n");
    fwrite(STDERR, "  php cli.php server:add --label=... --hostname=... --ssh-key=...\n");
    fwrite(STDERR, "  php cli.php server:test --id=1 [--timeout=10]\n");
    fwrite(STDERR, "  php cli.php token:create [--server-id=1] [--ttl-minutes=60]\n");
    fwrite(STDERR, "  php cli.php job:run --id=<uuid> [--dry-run] [--timeout=300]\n");
    fwrite(STDERR, "  php cli.php alerts:heartbeats [--threshold=30]\n");
    exit(1);
}

$connection = $app['connection'];
$migrator = $app['migrator'];
$config = $app['config'];

switch ($command) {
    case 'migrate':
        $applied = $migrator->migrate();
        if ($applied === []) {
            fwrite(STDOUT, "No new migrations.\n");
            exit(0);
        }

        foreach ($applied as $version => $file) {
            fwrite(STDOUT, sprintf("Applied migration %d: %s\n", $version, basename($file)));
        }
        exit(0);

    case 'migrate:fresh':
        $connection->dropDatabaseIfExists();
        $applied = $migrator->migrate();

        fwrite(STDOUT, "Dropped and recreated database.\n");
        if ($applied === []) {
            fwrite(STDOUT, "No new migrations.\n");
            exit(0);
        }

        foreach ($applied as $version => $file) {
            fwrite(STDOUT, sprintf("Applied migration %d: %s\n", $version, basename($file)));
        }
        exit(0);

    case 'user:create':
        $username = $argv[2] ?? null;
        $email = $argv[3] ?? null;

        if ($username === null || $email === null) {
            fwrite(STDERR, "Usage: php cli.php user:create <username> <email> [--role=admin|operator] [--password=secret]\n");
            exit(1);
        }

        $options = parse_cli_options(array_slice($argv, 4));
        $role = $options['role'] ?? 'operator';
        $password = $options['password'] ?? generate_password();
        $connection->ensureDatabaseExists();
        $db = $connection->pdo();

        $user = User::create($db, [
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ]);

        fwrite(STDOUT, sprintf("Created user #%d (%s) with role %s\n", $user->id, $user->username, $user->role));
        if (!isset($options['password'])) {
            fwrite(STDOUT, sprintf("Generated password: %s\n", $password));
        }
        exit(0);

    case 'server:add':
        $options = parse_cli_options(array_slice($argv, 2));
        $connection->ensureDatabaseExists();
        $db = $connection->pdo();

        $server = Server::create($db, [
            'label' => $options['label'] ?? '',
            'hostname' => $options['hostname'] ?? '',
            'ssh_key_path' => $options['ssh-key'] ?? '',
            'ssh_port' => $options['ssh-port'] ?? 22,
            'ssh_user' => $options['ssh-user'] ?? 'deploy',
            'php_path' => $options['php-path'] ?? '/usr/bin/php',
            'wp_cli_path' => $options['wp-cli'] ?? '/usr/local/bin/wp',
            'max_parallel' => $options['max-parallel'] ?? 4,
        ]);

        fwrite(STDOUT, sprintf(
            "Created server #%d %s (%s:%d)\n",
            $server->id,
            $server->label,
            $server->hostname,
            $server->sshPort
        ));
        exit(0);

    case 'token:create':
        $options = parse_cli_options(array_slice($argv, 2));
        $connection->ensureDatabaseExists();
        $db = $connection->pdo();

        $token = PairingToken::create(
            $db,
            isset($options['server-id']) ? (int) $options['server-id'] : null,
            isset($options['ttl-minutes']) ? (int) $options['ttl-minutes'] : 60
        );

        fwrite(STDOUT, sprintf("Token: %s\n", $token->token));
        fwrite(STDOUT, sprintf("Token ID: %s\n", $token->id));
        fwrite(STDOUT, sprintf("Expires At: %s\n", $token->expiresAt));
        if ($token->serverId !== null) {
            fwrite(STDOUT, sprintf("Server ID: %d\n", $token->serverId));
        }
        exit(0);

    case 'server:test':
        $options = parse_cli_options(array_slice($argv, 2));
        $serverId = isset($options['id']) ? (int) $options['id'] : 0;
        if ($serverId < 1) {
            fwrite(STDERR, "Usage: php cli.php server:test --id=1 [--timeout=10]\n");
            exit(1);
        }

        $connection->ensureDatabaseExists();
        $db = $connection->pdo();
        $server = Server::findById($db, $serverId);
        if (!$server instanceof Server) {
            fwrite(STDERR, sprintf("Server %d not found.\n", $serverId));
            exit(1);
        }

        try {
            $client = new SshClient();
            $result = $client->testConnection(
                $server,
                isset($options['timeout']) ? (int) $options['timeout'] : 10
            );
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("Server test failed: %s\n", $exception->getMessage()));
            exit(1);
        }

        fwrite(STDOUT, sprintf("Command: %s\n", $result->command));
        fwrite(STDOUT, sprintf("Exit Code: %d\n", $result->exitCode));
        fwrite(STDOUT, sprintf("Duration: %d ms\n", $result->durationMs));
        if ($result->stdout !== '') {
            fwrite(STDOUT, "STDOUT:\n" . $result->stdout . "\n");
        }
        if ($result->stderr !== '') {
            fwrite(STDOUT, "STDERR:\n" . $result->stderr . "\n");
        }

        exit($result->successful() ? 0 : 1);

    case 'job:run':
        $options = parse_cli_options(array_slice($argv, 2));
        $jobId = trim((string) ($options['id'] ?? ''));
        if ($jobId === '') {
            fwrite(STDERR, "Usage: php cli.php job:run --id=<uuid> [--dry-run] [--timeout=300]\n");
            exit(1);
        }

        $connection->ensureDatabaseExists();
        $db = $connection->pdo();
        $job = Job::findById($db, $jobId);
        if (!$job instanceof Job) {
            fwrite(STDERR, sprintf("Job %s not found.\n", $jobId));
            exit(1);
        }

        $executor = new JobExecutor(
            $db,
            new SshClient(),
            (int) $config->get('queue.default_timeout', 300),
            new ActivityLogService($db)
        );

        try {
            $result = $executor->execute(
                $job,
                isset($options['dry-run']),
                isset($options['timeout']) ? (int) $options['timeout'] : null
            );
        } catch (Throwable $exception) {
            fwrite(STDERR, sprintf("Job execution failed: %s\n", $exception->getMessage()));
            exit(1);
        }

        fwrite(STDOUT, sprintf("Command: %s\n", $result->command));
        fwrite(STDOUT, sprintf("Dry Run: %s\n", $result->dryRun ? 'yes' : 'no'));
        fwrite(STDOUT, sprintf("Exit Code: %d\n", $result->exitCode));
        fwrite(STDOUT, sprintf("Duration: %d ms\n", $result->durationMs));
        if ($result->stdout !== '') {
            fwrite(STDOUT, "STDOUT:\n" . $result->stdout . "\n");
        }
        if ($result->stderr !== '') {
            fwrite(STDOUT, "STDERR:\n" . $result->stderr . "\n");
        }

        exit($result->successful() ? 0 : 1);

    case 'alerts:heartbeats':
        $options = parse_cli_options(array_slice($argv, 2));
        $connection->ensureDatabaseExists();
        $db = $connection->pdo();
        $activityLog = new ActivityLogService($db);
        $alerts = new AlertService($db, $config, $activityLog);
        $threshold = isset($options['threshold']) ? (int) $options['threshold'] : null;
        if ($threshold !== null && $threshold < 1) {
            fwrite(STDERR, "Threshold must be at least 1 minute.\n");
            exit(1);
        }

        $sent = $alerts->checkMissedHeartbeats($threshold);
        fwrite(STDOUT, sprintf("Alerts sent: %d\n", $sent));
        exit(0);

    default:
        fwrite(STDERR, sprintf("Unknown command: %s\n", $command));
        exit(1);
}

function parse_cli_options(array $args): array
{
    $options = [];

    foreach ($args as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $options[$key] = $value;
    }

    return $options;
}

function generate_password(int $length = 20): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
    $maxIndex = strlen($alphabet) - 1;
    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $maxIndex)];
    }

    return $password;
}
