<?php

declare(strict_types=1);

namespace JetWP\Control\Models;

use InvalidArgumentException;
use PDO;

final class Server
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly string $hostname,
        public readonly int $sshPort,
        public readonly string $sshUser,
        public readonly string $sshKeyPath,
        public readonly string $phpPath,
        public readonly string $wpCliPath,
        public readonly int $maxParallel,
        public readonly bool $isActive,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    public static function create(PDO $db, array $attributes): self
    {
        $label = trim((string) ($attributes['label'] ?? ''));
        $hostname = trim((string) ($attributes['hostname'] ?? ''));
        $sshKeyPath = trim((string) ($attributes['ssh_key_path'] ?? ''));

        if ($label === '' || $hostname === '' || $sshKeyPath === '') {
            throw new InvalidArgumentException('label, hostname, and ssh_key_path are required.');
        }

        $sshPort = max(1, (int) ($attributes['ssh_port'] ?? 22));
        $sshUser = trim((string) ($attributes['ssh_user'] ?? 'deploy'));
        $phpPath = trim((string) ($attributes['php_path'] ?? '/usr/bin/php'));
        $wpCliPath = trim((string) ($attributes['wp_cli_path'] ?? '/usr/local/bin/wp'));
        $maxParallel = max(1, (int) ($attributes['max_parallel'] ?? 4));

        $statement = $db->prepare(
            'INSERT INTO servers (label, hostname, ssh_port, ssh_user, ssh_key_path, php_path, wp_cli_path, max_parallel)
             VALUES (:label, :hostname, :ssh_port, :ssh_user, :ssh_key_path, :php_path, :wp_cli_path, :max_parallel)'
        );
        $statement->execute([
            'label' => $label,
            'hostname' => $hostname,
            'ssh_port' => $sshPort,
            'ssh_user' => $sshUser,
            'ssh_key_path' => $sshKeyPath,
            'php_path' => $phpPath,
            'wp_cli_path' => $wpCliPath,
            'max_parallel' => $maxParallel,
        ]);

        return self::findById($db, (int) $db->lastInsertId());
    }

    public static function findById(PDO $db, int $id): ?self
    {
        $statement = $db->prepare('SELECT * FROM servers WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * @return list<self>
     */
    public static function all(PDO $db): array
    {
        $statement = $db->query('SELECT * FROM servers ORDER BY label ASC, hostname ASC');
        $servers = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $servers[] = self::fromRow($row);
            }
        }

        return $servers;
    }

    private static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            label: (string) $row['label'],
            hostname: (string) $row['hostname'],
            sshPort: (int) $row['ssh_port'],
            sshUser: (string) $row['ssh_user'],
            sshKeyPath: (string) $row['ssh_key_path'],
            phpPath: (string) $row['php_path'],
            wpCliPath: (string) $row['wp_cli_path'],
            maxParallel: (int) $row['max_parallel'],
            isActive: (bool) $row['is_active'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
