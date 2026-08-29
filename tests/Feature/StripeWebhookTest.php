<?php

use Larasell\Larasell\Enums\Currency;
use Larasell\Larasell\Enums\OrderStatus;
use Larasell\Larasell\Enums\PaymentStatus;
use Larasell\Larasell\Enums\RefundStatus;
use Larasell\Larasell\Models\Order;
use Larasell\Larasell\Models\Payment;
use Larasell\Larasell\Models\Refund;
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

it('finalizes pending refunds from signed Stripe webhooks only once', function () {
    [, $payment] = stripeWebhookPayment('cs_refund_succeeded', 'REFUND-SUCCEEDED');
    $payment->markAsPaid();
    $refund = $payment->refunds()->create([
        'provider' => 'stripe',
        'reference' => 're_succeeded',
        'status' => RefundStatus::Pending,
        'amount' => Price::of(400),
    ]);
    $payload = stripeRefundEventPayload('evt_refund_succeeded', 'refund.updated', $refund, 'succeeded');

    stripeWebhookRequest($this, $payload)->assertOk();
    stripeWebhookRequest($this, $payload)->assertOk();

    expect($refund->fresh()->status)->toBe(RefundStatus::Succeeded)
        ->and(StripeWebhookEvent::query()->where('provider_event_id', 'evt_refund_succeeded')->count())->toBe(1);
});

it('records failed refunds reported by Stripe', function () {
    [, $payment] = stripeWebhookPayment('cs_refund_failed', 'REFUND-FAILED');
    $payment->markAsPaid();
    $refund = $payment->refunds()->create([
        'provider' => 'stripe',
        'reference' => 're_failed',
        'status' => RefundStatus::Pending,
        'amount' => Price::of(400),
    ]);

    stripeWebhookRequest($this, stripeRefundEventPayload(
        'evt_refund_failed',
        'refund.failed',
        $refund,
        'failed',
        ['failure_reason' => 'insufficient_funds'],
    ))->assertOk();

    expect($refund->fresh()->status)->toBe(RefundStatus::Failed)
        ->and($refund->fresh()->failure_message)->toBe('insufficient_funds');
});

it('recovers a refund reference when the Stripe API response was lost', function () {
    [, $payment] = stripeWebhookPayment('cs_refund_recovered', 'REFUND-RECOVERED');
    $payment->markAsPaid();
    $refund = $payment->refunds()->create([
        'provider' => 'stripe',
        'status' => RefundStatus::Pending,
        'amount' => Price::of(400),
    ]);
    $payload = stripeRefundEventPayload(
        'evt_refund_recovered',
        'refund.created',
        $refund,
        'succeeded',
        ['id' => 're_recovered'],
    );

    stripeWebhookRequest($this, $payload)->assertOk();

    expect($refund->fresh()->reference)->toBe('re_recovered')
        ->and($refund->fresh()->status)->toBe(RefundStatus::Succeeded);
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

/** @param array<string, mixed> $overrides */
function stripeRefundEventPayload(
    string $id,
    string $type,
    Refund $refund,
    string $status,
    array $overrides = [],
): string {
    $stripeRefund = array_replace_recursive([
        'id' => $refund->reference,
        'object' => 'refund',
        'status' => $status,
        'metadata' => [
            'payment_id' => (string) $refund->payment_id,
            'refund_id' => (string) $refund->id,
        ],
    ], $overrides);

    return json_encode([
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => $stripeRefund],
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
