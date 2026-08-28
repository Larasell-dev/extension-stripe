<?php

namespace Larasell\Stripe\Contracts;

use Stripe\Checkout\Session;

interface CreatesCheckoutSessions
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     */
    public function create(array $parameters, array $options = []): Session;
}
