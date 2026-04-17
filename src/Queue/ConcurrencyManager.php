<?php

declare(strict_types=1);

namespace JetWP\Control\Queue;

use JetWP\Control\Models\Server;
use JetWP\Control\Models\Site;
use PDO;

final class ConcurrencyManager
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function hasCapacityForSite(string $siteId): bool
    {
        $site = Site::findById($this->db, $siteId);
        if (!$site instanceof Site) {
            return false;
        }

        $server = Server::findById($this->db, $site->serverId);
        if (!$server instanceof Server) {
            return false;
        }

        $lockName = sprintf('jetwp_server_%d', $server->id);
        $lockStatement = $this->db->prepare('SELECT GET_LOCK(:name, 0) AS acquired');
        $lockStatement->execute(['name' => $lockName]);
        $acquired = (string) $lockStatement->fetchColumn() === '1';

        if (!$acquired) {
            return false;
        }

        try {
            $statement = $this->db->prepare(
                'SELECT COUNT(*)
                 FROM jobs j
                 INNER JOIN sites s ON s.id = j.site_id
                 WHERE s.server_id = :server_id
                   AND j.status = :status'
            );
            $statement->execute([
                'server_id' => $server->id,
                'status' => 'running',
            ]);

            // The currently claimed job is already marked running at this point,
            // so capacity is still available while the count is equal to the limit.
            return (int) $statement->fetchColumn() <= $server->maxParallel;
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
        }
    }
}
