<?php

namespace Larasell\Stripe\Contracts;

use Stripe\Refund;

interface CreatesRefunds
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     */
    public function create(string $checkoutSession, array $parameters, array $options = []): Refund;
}
