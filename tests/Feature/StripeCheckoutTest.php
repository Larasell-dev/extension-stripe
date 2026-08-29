<?php

use Larasell\Larasell\Checkout\Checkout;
use Larasell\Larasell\Enums\Currency;
use Larasell\Larasell\Enums\OrderStatus;
use Larasell\Larasell\Enums\PaymentStatus;
use Larasell\Larasell\Enums\RefundStatus;
use Larasell\Larasell\Enums\Visibility;
use Larasell\Larasell\Models\Cart;
use Larasell\Larasell\Models\Order;
use Larasell\Larasell\Models\Payment;
use Larasell\Larasell\Models\Product;
use Larasell\Larasell\Payments\PaymentRequest;
use Larasell\Larasell\Payments\RedirectPaymentAction;
use Larasell\Larasell\Price;
use Larasell\Stripe\Contracts\CreatesCheckoutSessions;
use Larasell\Stripe\Contracts\CreatesRefunds;
use Larasell\Stripe\StripePaymentProvider;
use Stripe\Checkout\Session;
use Stripe\Refund;

final class FakeCheckoutSessions implements CreatesCheckoutSessions
{
    public array $parameters = [];

    public array $options = [];

    public function create(array $parameters, array $options = []): Session
    {
        $this->parameters = $parameters;
        $this->options = $options;

        return Session::constructFrom([
            'id' => 'cs_test_larasell',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.test/c/pay/cs_test_larasell',
        ]);
    }
}

final class FakeStripeRefunds implements CreatesRefunds
{
    public ?string $failureReason = null;

    public string $status = Refund::STATUS_SUCCEEDED;

    public string $checkoutSession = '';

    public array $parameters = [];

    public array $options = [];

    public function create(string $checkoutSession, array $parameters, array $options = []): Refund
    {
        $this->checkoutSession = $checkoutSession;
        $this->parameters = $parameters;
        $this->options = $options;

        return Refund::constructFrom([
            'id' => 're_test_larasell',
            'object' => 'refund',
            'status' => $this->status,
            'failure_reason' => $this->failureReason,
        ]);
    }
}

it('creates a Stripe Checkout Session and returns a redirect action', function () {
    $sessions = new FakeCheckoutSessions;
    app()->instance(CreatesCheckoutSessions::class, $sessions);
    config()->set('larasell.payments.methods.stripe', [
        'driver' => 'stripe',
        'provider' => StripePaymentProvider::class,
    ]);

    $product = Product::query()->create([
        'slug' => 'stripe-coffee',
        'name' => 'Stripe coffee',
        'price' => Price::of(1299),
        'stock' => 10,
        'allow_backorders' => false,
        'status' => Visibility::Visible,
    ]);
    $cart = Cart::query()->create(['currency' => Currency::EUR]);
    $cart->add($product, 2);

    $result = app(Checkout::class)->create(
        $cart,
        [
            'customer_email' => 'stripe@example.com',
            'customer_name' => 'Stripe Customer',
        ],
        'stripe',
        [
            'success_url' => 'https://shop.test/success',
            'cancel_url' => 'https://shop.test/cancel',
            'session_options' => ['locale' => 'de'],
        ],
    );

    expect($result->payment->failure_message)->toBeNull()
        ->and($result->payment->status)->toBe(PaymentStatus::Pending)
        ->and($result->payment->reference)->toBe('cs_test_larasell')
        ->and($result->action)->toBeInstanceOf(RedirectPaymentAction::class)
        ->and($result->action->url)->toBe('https://checkout.stripe.test/c/pay/cs_test_larasell')
        ->and($sessions->parameters['mode'])->toBe('payment')
        ->and($sessions->parameters['locale'])->toBe('de')
        ->and($sessions->parameters['line_items'][0]['quantity'])->toBe(2)
        ->and($sessions->parameters['line_items'][0]['price_data']['unit_amount'])->toBe(1299)
        ->and($sessions->parameters['metadata']['payment_id'])->toBe((string) $result->payment->id)
        ->and($sessions->parameters['payment_intent_data']['metadata']['order_id'])->toBe((string) $result->order->id)
        ->and($sessions->options['idempotency_key'])->toBe('larasell-payment-'.$result->payment->id);
});

it('records a useful message when Stripe omits the refund failure reason', function () {
    $refunds = new FakeStripeRefunds;
    $refunds->status = Refund::STATUS_FAILED;
    app()->instance(CreatesRefunds::class, $refunds);
    config()->set('larasell.payments.methods.stripe', [
        'driver' => 'stripe',
        'provider' => StripePaymentProvider::class,
    ]);
    $order = Order::query()->create([
        'number' => 'STRIPE-REFUND-FAILED',
        'currency' => Currency::EUR,
        'customer_email' => 'refund@example.com',
        'customer_name' => 'Refund Customer',
        'status' => OrderStatus::Paid,
        'subtotal' => Price::of(2000),
        'total' => Price::of(2000),
    ]);
    $payment = $order->payments()->create([
        'method' => 'stripe',
        'provider' => 'stripe',
        'reference' => 'cs_failed_refund',
        'status' => PaymentStatus::Succeeded,
        'amount' => Price::of(2000),
        'paid_at' => now(),
    ]);

    $refund = $payment->refund();

    expect($refund->status)->toBe(RefundStatus::Failed)
        ->and($refund->failure_message)->toBe('Stripe reported that the refund failed.');
});

it('requires success and cancel URLs', function () {
    $sessions = new FakeCheckoutSessions;
    $provider = new StripePaymentProvider($sessions);
    $order = new Order;
    $payment = new Payment;

    expect(fn () => $provider->initiate(new PaymentRequest(
        'stripe',
        $order,
        $payment,
    )))->toThrow(InvalidArgumentException::class, 'The Stripe success_url payment option is required.');
});

it('creates a Stripe refund for a successful payment', function () {
    $refunds = new FakeStripeRefunds;
    app()->instance(CreatesRefunds::class, $refunds);
    config()->set('larasell.payments.methods.stripe', [
        'driver' => 'stripe',
        'provider' => StripePaymentProvider::class,
    ]);
    $order = Order::query()->create([
        'number' => 'STRIPE-REFUND',
        'currency' => Currency::EUR,
        'customer_email' => 'refund@example.com',
        'customer_name' => 'Refund Customer',
        'status' => OrderStatus::Paid,
        'subtotal' => Price::of(2000),
        'total' => Price::of(2000),
    ]);
    $payment = $order->payments()->create([
        'method' => 'stripe',
        'provider' => 'stripe',
        'reference' => 'cs_refundable',
        'status' => PaymentStatus::Succeeded,
        'amount' => Price::of(2000),
        'paid_at' => now(),
    ]);

    $refund = $payment->refund(Price::of(750), [
        'refund_options' => [
            'reason' => 'requested_by_customer',
            'amount' => 1,
            'metadata' => ['custom' => 'value', 'refund_id' => 'wrong'],
        ],
        'api_options' => ['idempotency_key' => 'wrong'],
    ]);

    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->reference)->toBe('re_test_larasell')
        ->and($refunds->checkoutSession)->toBe('cs_refundable')
        ->and($refunds->parameters['amount'])->toBe(750)
        ->and($refunds->parameters['reason'])->toBe('requested_by_customer')
        ->and($refunds->parameters['metadata']['custom'])->toBe('value')
        ->and($refunds->parameters['metadata']['refund_id'])->toBe((string) $refund->id)
        ->and($refunds->parameters['metadata']['payment_id'])->toBe((string) $payment->id)
        ->and($refunds->options['idempotency_key'])->toBe('larasell-refund-'.$refund->id);
});
