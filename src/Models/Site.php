<?php

declare(strict_types=1);

namespace JetWP\Control\Models;

use InvalidArgumentException;
use JetWP\Control\Support\Uuid;
use PDO;

final class Site
{
    public function __construct(
        public readonly string $id,
        public readonly int $serverId,
        public readonly string $url,
        public readonly string $label,
        public readonly string $wpPath,
        public readonly string $hmacSecret,
        public readonly string $status,
        public readonly ?string $wpVersion,
        public readonly ?string $phpVersion,
        public readonly ?string $lastHeartbeatAt,
        public readonly string $registeredAt,
        public readonly string $updatedAt,
    ) {
    }

    public static function create(PDO $db, array $attributes): self
    {
        $serverId = (int) ($attributes['server_id'] ?? 0);
        $url = self::normalizeUrl((string) ($attributes['url'] ?? ''));
        $label = trim((string) ($attributes['label'] ?? ''));
        $wpPath = trim((string) ($attributes['wp_path'] ?? ''));
        $hmacSecret = (string) ($attributes['hmac_secret'] ?? '');
        $status = (string) ($attributes['status'] ?? 'active');
        $wpVersion = self::nullableString($attributes['wp_version'] ?? null);
        $phpVersion = self::nullableString($attributes['php_version'] ?? null);

        if ($serverId < 1 || $url === '' || $label === '' || $wpPath === '' || $hmacSecret === '') {
            throw new InvalidArgumentException('server_id, url, label, wp_path, and hmac_secret are required.');
        }

        if (!in_array($status, ['active', 'paused', 'unreachable', 'error'], true)) {
            throw new InvalidArgumentException('Site status is invalid.');
        }

        $id = (string) ($attributes['id'] ?? Uuid::v4());

        $statement = $db->prepare(
            'INSERT INTO sites (id, server_id, url, label, wp_path, hmac_secret, status, wp_version, php_version)
             VALUES (:id, :server_id, :url, :label, :wp_path, :hmac_secret, :status, :wp_version, :php_version)'
        );
        $statement->execute([
            'id' => $id,
            'server_id' => $serverId,
            'url' => $url,
            'label' => $label,
            'wp_path' => $wpPath,
            'hmac_secret' => $hmacSecret,
            'status' => $status,
            'wp_version' => $wpVersion,
            'php_version' => $phpVersion,
        ]);

        return self::findById($db, $id);
    }

    public static function findById(PDO $db, string $id): ?self
    {
        $statement = $db->prepare('SELECT * FROM sites WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    public static function findByUrl(PDO $db, string $url): ?self
    {
        $statement = $db->prepare('SELECT * FROM sites WHERE url = :url LIMIT 1');
        $statement->execute(['url' => self::normalizeUrl($url)]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * @return list<self>
     */
    public static function all(PDO $db): array
    {
        $statement = $db->query('SELECT * FROM sites ORDER BY label ASC, url ASC');
        $sites = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $sites[] = self::fromRow($row);
            }
        }

        return $sites;
    }

    public static function recordHeartbeat(PDO $db, string $id, ?string $wpVersion = null, ?string $phpVersion = null): void
    {
        $statement = $db->prepare(
            'UPDATE sites
             SET last_heartbeat_at = NOW(),
                 wp_version = COALESCE(:wp_version, wp_version),
                 php_version = COALESCE(:php_version, php_version)
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'wp_version' => self::nullableString($wpVersion),
            'php_version' => self::nullableString($phpVersion),
        ]);
    }

    public static function normalizeUrl(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return '';
        }

        $normalized = rtrim($normalized, '/');

        return $normalized === '' ? $url : $normalized;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            serverId: (int) $row['server_id'],
            url: (string) $row['url'],
            label: (string) $row['label'],
            wpPath: (string) $row['wp_path'],
            hmacSecret: (string) $row['hmac_secret'],
            status: (string) $row['status'],
            wpVersion: isset($row['wp_version']) ? (string) $row['wp_version'] : null,
            phpVersion: isset($row['php_version']) ? (string) $row['php_version'] : null,
            lastHeartbeatAt: isset($row['last_heartbeat_at']) ? (string) $row['last_heartbeat_at'] : null,
            registeredAt: (string) $row['registered_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
