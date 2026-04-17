<?php

declare(strict_types=1);

namespace JetWP\Control\Db;

use JetWP\Control\Config\Config;
use PDO;
use PDOException;
use RuntimeException;

final class Connection
{
    private ?PDO $pdo = null;
    private ?PDO $serverPdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $database = (string) $this->config->get('db.database', '');
        if ($database === '') {
            throw new RuntimeException('Database name must be configured.');
        }

        $this->pdo = $this->connect($database);

        return $this->pdo;
    }

    public function serverPdo(): PDO
    {
        if ($this->serverPdo instanceof PDO) {
            return $this->serverPdo;
        }

        $this->serverPdo = $this->connect(null);

        return $this->serverPdo;
    }

    public function ensureDatabaseExists(): void
    {
        $database = $this->databaseName();
        $charset = (string) $this->config->get('db.charset', 'utf8mb4');
        $collation = (string) $this->config->get('db.collation', 'utf8mb4_unicode_ci');

        $sql = sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
            $database,
            $charset,
            $collation
        );

        $this->serverPdo()->exec($sql);
    }

    public function dropDatabaseIfExists(): void
    {
        $database = $this->databaseName();
        $sql = sprintf('DROP DATABASE IF EXISTS `%s`', $database);

        $this->serverPdo()->exec($sql);
        $this->pdo = null;
    }

    private function databaseName(): string
    {
        $database = (string) $this->config->get('db.database', '');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new RuntimeException('Database name contains unsupported characters.');
        }

        return $database;
    }

    private function connect(?string $database): PDO
    {
        $host = (string) $this->config->get('db.host', '127.0.0.1');
        $port = (int) $this->config->get('db.port', 3306);
        $charset = (string) $this->config->get('db.charset', 'utf8mb4');
        $username = (string) $this->config->get('db.username', '');
        $password = (string) $this->config->get('db.password', '');

        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset);
        if ($database !== null) {
            $dsn .= sprintf(';dbname=%s', $database);
        }

        try {
            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
