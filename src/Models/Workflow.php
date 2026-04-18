<?php

declare(strict_types=1);

namespace JetWP\Control\Models;

use InvalidArgumentException;
use JetWP\Control\Support\Uuid;
use PDO;

final class Workflow
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $status,
        public readonly ?int $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    public static function create(PDO $db, array $attributes): self
    {
        $id = trim((string) ($attributes['id'] ?? Uuid::v4()));
        $name = trim((string) ($attributes['name'] ?? ''));
        $description = self::nullableString($attributes['description'] ?? null);
        $status = trim((string) ($attributes['status'] ?? 'draft'));
        $createdBy = isset($attributes['created_by']) ? (int) $attributes['created_by'] : null;

        if ($name === '') {
            throw new InvalidArgumentException('Workflow name is required.');
        }

        if (!in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new InvalidArgumentException('Workflow status is invalid.');
        }

        $statement = $db->prepare(
            'INSERT INTO workflows (id, name, description, status, created_by)
             VALUES (:id, :name, :description, :status, :created_by)'
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'created_by' => $createdBy,
        ]);

        return self::findById($db, $id);
    }

    public static function update(PDO $db, string $id, array $attributes): ?self
    {
        $workflow = self::findById($db, $id);
        if (!$workflow instanceof self) {
            return null;
        }

        $name = trim((string) ($attributes['name'] ?? $workflow->name));
        $description = array_key_exists('description', $attributes)
            ? self::nullableString($attributes['description'])
            : $workflow->description;
        $status = trim((string) ($attributes['status'] ?? $workflow->status));

        if ($name === '') {
            throw new InvalidArgumentException('Workflow name is required.');
        }

        if (!in_array($status, ['draft', 'active', 'archived'], true)) {
            throw new InvalidArgumentException('Workflow status is invalid.');
        }

        $statement = $db->prepare(
            'UPDATE workflows
             SET name = :name,
                 description = :description,
                 status = :status
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'status' => $status,
        ]);

        return self::findById($db, $id);
    }

    public static function findById(PDO $db, string $id): ?self
    {
        $statement = $db->prepare('SELECT * FROM workflows WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * @return list<self>
     */
    public static function all(PDO $db): array
    {
        $statement = $db->query('SELECT * FROM workflows ORDER BY updated_at DESC, name ASC');
        $items = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $items[] = self::fromRow($row);
            }
        }

        return $items;
    }

    private static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            name: (string) $row['name'],
            description: self::nullableString($row['description'] ?? null),
            status: (string) $row['status'],
            createdBy: isset($row['created_by']) ? (int) $row['created_by'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
