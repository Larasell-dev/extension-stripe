<?php

namespace Larasell\Stripe;

use Larasell\Stripe\Contracts\CreatesCheckoutSessions;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

final readonly class StripeCheckoutClient implements CreatesCheckoutSessions
{
    public function __construct(private StripeClient $stripe) {}

    public function create(array $parameters, array $options = []): Session
    {
        return $this->stripe->checkout->sessions->create($parameters, $options);
    }
}
