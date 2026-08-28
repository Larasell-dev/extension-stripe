<?php

namespace Larasell\Stripe\Tests;

use Larasell\Larasell\LarasellServiceProvider;
use Larasell\Stripe\StripeServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LarasellServiceProvider::class,
            StripeServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $connection = env('DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', $connection);
        $app['config']->set('database.connections.sqlite.database', env('DB_DATABASE', ':memory:'));
        $app['config']->set('larasell-stripe.secret', 'sk_test_package');
        $app['config']->set('larasell-stripe.webhook_secret', 'whsec_package');
    }
}
