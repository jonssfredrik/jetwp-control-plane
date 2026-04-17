<?php

declare(strict_types=1);

namespace JetWP\Control\Models;

use InvalidArgumentException;
use PDO;

final class Telemetry
{
    public static function latestForSite(PDO $db, string $siteId): ?array
    {
        if ($siteId === '') {
            throw new InvalidArgumentException('site_id is required.');
        }

        $statement = $db->prepare(
            'SELECT id, site_id, payload, received_at
             FROM telemetry
             WHERE site_id = :site_id
             ORDER BY received_at DESC, id DESC
             LIMIT 1'
        );
        $statement->execute(['site_id' => $siteId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return self::mapRow($row);
    }

    /**
     * @return list<array{id:int,site_id:string,payload:array,received_at:string}>
     */
    public static function recentForSite(PDO $db, string $siteId, int $limit = 25): array
    {
        if ($siteId === '') {
            throw new InvalidArgumentException('site_id is required.');
        }

        $limit = max(1, min($limit, 200));
        $statement = $db->prepare(
            'SELECT id, site_id, payload, received_at
             FROM telemetry
             WHERE site_id = :site_id
             ORDER BY received_at DESC, id DESC
             LIMIT ' . $limit
        );
        $statement->execute(['site_id' => $siteId]);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $items[] = self::mapRow($row);
            }
        }

        return $items;
    }

    public static function create(PDO $db, string $siteId, array $payload): void
    {
        if ($siteId === '') {
            throw new InvalidArgumentException('site_id is required.');
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('Telemetry payload could not be encoded as JSON.');
        }

        $statement = $db->prepare(
            'INSERT INTO telemetry (site_id, payload)
             VALUES (:site_id, :payload)'
        );
        $statement->execute([
            'site_id' => $siteId,
            'payload' => $json,
        ]);
    }

    private static function mapRow(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'site_id' => (string) $row['site_id'],
            'payload' => is_array($payload) ? $payload : [],
            'received_at' => (string) $row['received_at'],
        ];
    }
}
