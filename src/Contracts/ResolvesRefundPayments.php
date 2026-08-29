<?php

namespace Larasell\Stripe\Contracts;

use Stripe\Refund;

interface ResolvesRefundPayments
{
    public function resolve(Refund $refund): ?string;
}
