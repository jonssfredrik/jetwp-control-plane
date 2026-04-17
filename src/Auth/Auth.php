<?php

declare(strict_types=1);

namespace JetWP\Control\Auth;

use JetWP\Control\Models\User;
use JetWP\Control\Services\ActivityLogService;
use PDO;

final class Auth
{
    private const SESSION_USER_ID = 'jetwp_user_id';

    public function __construct(
        private readonly PDO $db,
        private readonly ?ActivityLogService $activityLog = null
    )
    {
    }

    public function attempt(string $username, string $password): bool
    {
        $user = User::findByUsername($this->db, $username);
        if (!$user instanceof User) {
            $this->activityLog?->logLoginFailure(null, $username, 'user_not_found');
            return false;
        }

        if ($this->isLocked($user)) {
            $this->activityLog?->logLoginFailure($user->id, $user->username, 'locked', [
                'locked_until' => $user->lockedUntil,
            ]);
            return false;
        }

        if (!Password::verify($password, $user->passwordHash)) {
            $this->recordFailedAttempt($user->id, $user->failedLoginAttempts);
            $this->activityLog?->logLoginFailure($user->id, $user->username, 'invalid_password', [
                'failed_attempts' => $user->failedLoginAttempts + 1,
            ]);
            return false;
        }

        $this->storeUser($user);
        $this->markLogin($user->id);
        $this->activityLog?->logLoginSuccess($user);

        return true;
    }

    public function check(): bool
    {
        return $this->user() instanceof User;
    }

    public function user(): ?User
    {
        $id = $_SESSION[self::SESSION_USER_ID] ?? null;
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }

        return User::findById($this->db, (int) $id);
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_USER_ID]);

        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    private function storeUser(User $user): void
    {
        $_SESSION[self::SESSION_USER_ID] = $user->id;

        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    private function markLogin(int $userId): void
    {
        $statement = $this->db->prepare(
            'UPDATE users
             SET last_login_at = NOW(), failed_login_attempts = 0, locked_until = NULL
             WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }

    private function isLocked(User $user): bool
    {
        if ($user->lockedUntil === null) {
            return false;
        }

        return strtotime($user->lockedUntil) > time();
    }

    private function recordFailedAttempt(int $userId, int $currentAttempts): void
    {
        $nextAttempts = $currentAttempts + 1;

        if ($nextAttempts >= 5) {
            $statement = $this->db->prepare(
                'UPDATE users
                 SET failed_login_attempts = :attempts,
                     locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                 WHERE id = :id'
            );
            $statement->execute([
                'attempts' => $nextAttempts,
                'id' => $userId,
            ]);
            return;
        }

        $statement = $this->db->prepare(
            'UPDATE users SET failed_login_attempts = :attempts WHERE id = :id'
        );
        $statement->execute([
            'attempts' => $nextAttempts,
            'id' => $userId,
        ]);
    }
}
