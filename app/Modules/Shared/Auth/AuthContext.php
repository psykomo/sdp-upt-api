<?php

namespace App\Modules\Shared\Auth;

final class AuthContext
{
    private static ?CurrentUser $user = null;

    public static function set(CurrentUser $user): void
    {
        self::$user = $user;
    }

    public static function user(): CurrentUser
    {
        if (self::$user === null) {
            throw new \RuntimeException('No authenticated user in context.');
        }

        return self::$user;
    }

    public static function tryUser(): ?CurrentUser
    {
        return self::$user;
    }

    public static function clear(): void
    {
        self::$user = null;
    }
}
