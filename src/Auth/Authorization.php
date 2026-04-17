<?php

declare(strict_types=1);

namespace JetWP\Control\Auth;

use JetWP\Control\Models\User;

final class Authorization
{
    public function ensureAuthenticated(?User $user): void
    {
        if (!$user instanceof User) {
            throw new AuthorizationException('Authentication is required.');
        }
    }

    public function ensureAdmin(?User $user): void
    {
        $this->ensureAuthenticated($user);

        if (!$user->isAdmin()) {
            throw new AuthorizationException('Admin access is required.');
        }
    }

    public function ensureJobsAccess(?User $user): void
    {
        $this->ensureAuthenticated($user);
    }

    public function ensureJobTypeAllowed(?User $user, string $type): void
    {
        $this->ensureAuthenticated($user);

        if ($type === 'custom.wp_cli' && !$user->isAdmin()) {
            throw new AuthorizationException('Only admins may create or retry custom.wp_cli jobs.');
        }
    }
}
