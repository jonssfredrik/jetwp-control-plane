<?php

declare(strict_types=1);

namespace JetWP\Control\Auth;

final class Password
{
    public static function hash(string $password, int $cost = 12): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
