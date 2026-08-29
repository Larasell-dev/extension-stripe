<?php

namespace Larasell\Stripe;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Larasell\Stripe\Contracts\CreatesCheckoutSessions;
use Stripe\StripeClient;

final class StripeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stripe.php', 'larasell-stripe');

        $this->app->singleton(StripeClient::class, function (): StripeClient {
            $secret = config('larasell-stripe.secret');

            if (! is_string($secret) || trim($secret) === '') {
                throw new InvalidArgumentException('A Stripe secret key must be configured.');
            }

            return new StripeClient($secret);
        });

        $this->app->singleton(CreatesCheckoutSessions::class, StripeCheckoutClient::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (Stripe::registersRoutes()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/stripe.php' => config_path('larasell-stripe.php'),
            ], 'larasell-stripe-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'larasell-stripe-migrations');
        }
    }
}
