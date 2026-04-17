<?php

declare(strict_types=1);

namespace JetWP\Control\Models;

use JetWP\Control\Auth\Password;
use InvalidArgumentException;
use PDO;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $email,
        public readonly string $passwordHash,
        public readonly string $role,
        public readonly int $failedLoginAttempts,
        public readonly ?string $lockedUntil,
        public readonly ?string $lastLoginAt,
        public readonly string $createdAt,
    ) {
    }

    public static function create(PDO $db, array $attributes): self
    {
        $username = trim((string) ($attributes['username'] ?? ''));
        $email = trim((string) ($attributes['email'] ?? ''));
        $password = (string) ($attributes['password'] ?? '');
        $role = (string) ($attributes['role'] ?? 'operator');

        if ($username === '' || $email === '' || $password === '') {
            throw new InvalidArgumentException('Username, email, and password are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email address is invalid.');
        }

        if (!in_array($role, ['admin', 'operator'], true)) {
            throw new InvalidArgumentException('Role must be admin or operator.');
        }

        $statement = $db->prepare(
            'INSERT INTO users (username, email, password_hash, role)
             VALUES (:username, :email, :password_hash, :role)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => Password::hash($password),
            'role' => $role,
        ]);

        return self::findById($db, (int) $db->lastInsertId());
    }

    public static function findById(PDO $db, int $id): ?self
    {
        $statement = $db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    public static function findByUsername(PDO $db, string $username): ?self
    {
        $statement = $db->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $row = $statement->fetch();

        return is_array($row) ? self::fromRow($row) : null;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOperator(): bool
    {
        return $this->role === 'operator';
    }

    private static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            username: (string) $row['username'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            role: (string) $row['role'],
            failedLoginAttempts: (int) ($row['failed_login_attempts'] ?? 0),
            lockedUntil: isset($row['locked_until']) ? (string) $row['locked_until'] : null,
            lastLoginAt: isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
            createdAt: (string) $row['created_at'],
        );
    }
}
