# Larasell Stripe

Stripe Checkout payments for [Larasell](https://github.com/Larasell-dev/larasell).

## Installation

```bash
composer require larasell-dev/stripe
```

Add the Stripe credentials to the application environment:

```dotenv
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Register the payment method in `config/larasell.php`:

```php
use Larasell\Stripe\StripePaymentProvider;

'payments' => [
    'default' => 'stripe',
    'methods' => [
        'stripe' => [
            'driver' => 'stripe',
            'provider' => StripePaymentProvider::class,
        ],
    ],
],
```

## Checkout

The storefront supplies its success and cancellation URLs:

```php
$result = $checkout->create(
    cart: $cart,
    data: $customerData,
    paymentMethod: 'stripe',
    paymentOptions: [
        'success_url' => route('checkout.success', absolute: true),
        'cancel_url' => route('checkout.cancel', absolute: true),
    ],
);

return $result->requiresRedirect()
    ? $result->redirect()
    : redirect()->route('orders.show', $result->order);
```

Additional Stripe Checkout Session options can be supplied under
`session_options`. Larasell-controlled amount, customer, URL, and metadata
fields cannot be overridden.

```php
paymentOptions: [
    'success_url' => route('checkout.success', absolute: true),
    'cancel_url' => route('checkout.cancel', absolute: true),
    'session_options' => [
        'locale' => 'de',
        'allow_promotion_codes' => true,
    ],
],
```

## Webhooks

The package registers:

```text
POST /larasell/stripe/webhook
```

Configure Stripe to deliver these events:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`

Webhook signatures are verified and Stripe event IDs are stored to prevent
duplicate processing. Browser redirects never mark an order as paid.

Also configure Stripe to deliver the refund lifecycle events:

- `refund.created`
- `refund.updated`
- `refund.failed`

## Refunds

Create full or partial refunds from a successful Stripe payment:

```php
$refund = $payment->refund();

$refund = $payment->refund(Price::of(2500));
```

Amounts use the same integer minor units as Larasell prices. Stripe refund
options such as a reason can be passed separately:

```php
$refund = $payment->refund(Price::of(2500), [
    'refund_options' => [
        'reason' => 'requested_by_customer',
    ],
]);
```

The initial Stripe response sets the local refund status. Pending refunds are
subsequently finalized by signed webhooks. Refunding never cancels an order;
an unfulfilled, fully refunded order can be cancelled explicitly.

To register the webhook route yourself, call this before package providers boot:

```php
use Larasell\Stripe\Stripe;

Stripe::ignoreRoutes();
```

Publish the configuration or migrations when customization is required:

```bash
php artisan vendor:publish --tag=larasell-stripe-config
php artisan vendor:publish --tag=larasell-stripe-migrations
```
