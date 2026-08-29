<?php

namespace Larasell\Stripe;

use Larasell\Stripe\Contracts\CreatesRefunds;
use RuntimeException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

final readonly class StripeRefundClient implements CreatesRefunds
{
    public function __construct(private StripeClient $stripe) {}

    public function create(string $checkoutSession, array $parameters, array $options = []): Refund
    {
        $session = $this->stripe->checkout->sessions->retrieve($checkoutSession);
        $paymentIntent = $session->payment_intent;
        $paymentIntent = $paymentIntent instanceof PaymentIntent ? $paymentIntent->id : $paymentIntent;

        if (! is_string($paymentIntent) || $paymentIntent === '') {
            throw new RuntimeException('The Stripe Checkout Session does not have a PaymentIntent to refund.');
        }

        return $this->stripe->refunds->create(
            array_replace($parameters, ['payment_intent' => $paymentIntent]),
            $options,
        );
    }
}
