<?php

namespace Larasell\Stripe;

use Larasell\Stripe\Contracts\ResolvesRefundPayments;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

final readonly class StripeRefundPaymentResolver implements ResolvesRefundPayments
{
    public function __construct(private StripeClient $stripe) {}

    public function resolve(Refund $refund): ?string
    {
        $paymentIntent = $refund->payment_intent;

        if (! $paymentIntent instanceof PaymentIntent) {
            if (! is_string($paymentIntent) || $paymentIntent === '') {
                return null;
            }

            $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntent);
        }

        $paymentId = $paymentIntent->metadata->payment_id ?? null;

        return is_string($paymentId) && $paymentId !== '' ? $paymentId : null;
    }
}
