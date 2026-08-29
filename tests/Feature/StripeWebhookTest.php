<?php

use Larasell\Larasell\Enums\Currency;
use Larasell\Larasell\Enums\OrderStatus;
use Larasell\Larasell\Enums\PaymentStatus;
use Larasell\Larasell\Models\Order;
use Larasell\Larasell\Models\Payment;
use Larasell\Larasell\Price;
use Larasell\Stripe\Models\StripeWebhookEvent;

it('rejects webhooks with an invalid signature', function () {
    $this->postJson('/larasell/stripe/webhook', [])
        ->assertBadRequest();
});

it('marks a payment as paid from a signed completed event only once', function () {
    [$order, $payment] = stripeWebhookPayment('cs_paid');
    $payload = stripeEventPayload('evt_paid', 'checkout.session.completed', $payment, [
        'payment_status' => 'paid',
    ]);

    stripeWebhookRequest($this, $payload)->assertOk();
    stripeWebhookRequest($this, $payload)->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and(StripeWebhookEvent::query()->where('provider_event_id', 'evt_paid')->count())->toBe(1);
});

it('maps asynchronous failures and expired sessions', function () {
    [$failedOrder, $failedPayment] = stripeWebhookPayment('cs_failed', 'FAILED');
    stripeWebhookRequest($this, stripeEventPayload(
        'evt_failed',
        'checkout.session.async_payment_failed',
        $failedPayment,
    ))->assertOk();

    [$expiredOrder, $expiredPayment] = stripeWebhookPayment('cs_expired', 'EXPIRED');
    stripeWebhookRequest($this, stripeEventPayload(
        'evt_expired',
        'checkout.session.expired',
        $expiredPayment,
    ))->assertOk();

    expect($failedPayment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($failedOrder->fresh()->status)->toBe(OrderStatus::PaymentFailed)
        ->and($expiredPayment->fresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and($expiredOrder->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('ignores unpaid completed sessions and metadata mismatches', function () {
    [$order, $payment] = stripeWebhookPayment('cs_ignored', 'IGNORED');

    stripeWebhookRequest($this, stripeEventPayload(
        'evt_unpaid',
        'checkout.session.completed',
        $payment,
        ['payment_status' => 'unpaid'],
    ))->assertOk();

    stripeWebhookRequest($this, stripeEventPayload(
        'evt_mismatch',
        'checkout.session.async_payment_succeeded',
        $payment,
        ['metadata' => ['payment_id' => '999999']],
    ))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

/** @return array{Order, Payment} */
function stripeWebhookPayment(string $reference, string $number = 'WEBHOOK'): array
{
    $order = Order::query()->create([
        'number' => $number,
        'currency' => Currency::EUR,
        'customer_email' => 'webhook@example.com',
        'customer_name' => 'Webhook Customer',
        'status' => OrderStatus::PendingPayment,
        'subtotal' => Price::of(1000),
        'total' => Price::of(1000),
    ]);
    $payment = $order->payments()->create([
        'method' => 'stripe',
        'provider' => 'stripe',
        'reference' => $reference,
        'status' => PaymentStatus::Pending,
        'amount' => $order->total,
    ]);

    return [$order, $payment];
}

/** @param array<string, mixed> $overrides */
function stripeEventPayload(string $id, string $type, Payment $payment, array $overrides = []): string
{
    $session = array_replace_recursive([
        'id' => $payment->reference,
        'object' => 'checkout.session',
        'payment_status' => 'unpaid',
        'metadata' => ['payment_id' => (string) $payment->id],
    ], $overrides);

    return json_encode([
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => $session],
    ], JSON_THROW_ON_ERROR);
}

function stripeWebhookRequest($test, string $payload)
{
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_package');

    return $test->call(
        'POST',
        '/larasell/stripe/webhook',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ],
        content: $payload,
    );
}
