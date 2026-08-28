<?php

namespace Larasell\Stripe;

final class Stripe
{
    private static bool $registersRoutes = true;

    public static function ignoreRoutes(): void
    {
        self::$registersRoutes = false;
    }

    public static function registersRoutes(): bool
    {
        return self::$registersRoutes;
    }
}
