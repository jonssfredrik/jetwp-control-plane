<?php

declare(strict_types=1);

namespace JetWP\Control\Db;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $migrationsPath
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function migrate(): array
    {
        $this->connection->ensureDatabaseExists();
        $pdo = $this->connection->pdo();

        $this->ensureMigrationsTable($pdo);
        $applied = $this->appliedVersions($pdo);
        $files = $this->migrationFiles();
        $ran = [];

        foreach ($files as $version => $file) {
            if (isset($applied[$version])) {
                continue;
            }

            $sql = trim((string) file_get_contents($file));
            if ($sql === '') {
                throw new RuntimeException(sprintf('Migration file %s is empty.', $file));
            }

            try {
                $pdo->exec($sql);
                $statement = $pdo->prepare('INSERT INTO migrations (version) VALUES (:version)');
                $statement->execute(['version' => $version]);
            } catch (\Throwable $exception) {
                throw new RuntimeException(sprintf('Migration %s failed: %s', basename($file), $exception->getMessage()), 0, $exception);
            }

            $ran[$version] = $file;
        }

        return $ran;
    }

    private function ensureMigrationsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                version INT UNSIGNED PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB'
        );
    }

    /**
     * @return array<int, bool>
     */
    private function appliedVersions(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT version FROM migrations ORDER BY version ASC')->fetchAll(PDO::FETCH_COLUMN);
        $versions = [];

        foreach ($rows as $version) {
            $versions[(int) $version] = true;
        }

        return $versions;
    }

    /**
     * @return array<int, string>
     */
    private function migrationFiles(): array
    {
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);

        $migrations = [];
        foreach ($files as $file) {
            if (!preg_match('/^(\d+)_.*\.sql$/', basename($file), $matches)) {
                throw new RuntimeException(sprintf('Invalid migration filename: %s', basename($file)));
            }

            $migrations[(int) $matches[1]] = $file;
        }

        return $migrations;
    }
}
