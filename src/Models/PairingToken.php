<?php

declare(strict_types=1);

namespace JetWP\Control\Models;

use DateTimeImmutable;
use JetWP\Control\Support\Uuid;
use PDO;

final class PairingToken
{
    public function __construct(
        public readonly string $id,
        public readonly string $token,
        public readonly ?int $serverId,
        public readonly bool $used,
        public readonly string $expiresAt,
        public readonly string $createdAt,
    ) {
    }

    public static function create(PDO $db, ?int $serverId = null, int $ttlMinutes = 60): self
    {
        $id = Uuid::v4();
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new DateTimeImmutable(sprintf('+%d minutes', max(1, $ttlMinutes))))
            ->format('Y-m-d H:i:s');

        $statement = $db->prepare(
            'INSERT INTO pairing_tokens (id, token, server_id, used, expires_at)
             VALUES (:id, :token, :server_id, 0, :expires_at)'
        );
        $statement->execute([
            'id' => $id,
            'token' => $token,
            'server_id' => $serverId,
            'expires_at' => $expiresAt,
        ]);

        return new self(
            id: $id,
            token: $token,
            serverId: $serverId,
            used: false,
            expiresAt: $expiresAt,
            createdAt: (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        );
    }

    public static function lockByToken(PDO $db, string $token): ?self
    {
        $statement = $db->prepare('SELECT * FROM pairing_tokens WHERE token = :token LIMIT 1 FOR UPDATE');
        $statement->execute(['token' => trim($token)]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    public static function markUsed(PDO $db, string $id): void
    {
        $statement = $db->prepare('UPDATE pairing_tokens SET used = 1 WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    private static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            token: (string) $row['token'],
            serverId: isset($row['server_id']) ? (int) $row['server_id'] : null,
            used: (bool) $row['used'],
            expiresAt: (string) $row['expires_at'],
            createdAt: (string) $row['created_at'],
        );
    }
}
