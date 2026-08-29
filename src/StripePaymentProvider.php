<?php

namespace Larasell\Stripe;

use InvalidArgumentException;
use Larasell\Larasell\Contracts\PaymentProvider;
use Larasell\Larasell\Contracts\RefundProvider;
use Larasell\Larasell\Payments\PaymentRequest;
use Larasell\Larasell\Payments\PaymentResult;
use Larasell\Larasell\Payments\RedirectPaymentAction;
use Larasell\Larasell\Refunds\RefundRequest;
use Larasell\Larasell\Refunds\RefundResult;
use Larasell\Stripe\Contracts\CreatesCheckoutSessions;
use Larasell\Stripe\Contracts\CreatesRefunds;
use RuntimeException;
use Stripe\Refund;

final readonly class StripePaymentProvider implements PaymentProvider, RefundProvider
{
    public function __construct(
        private CreatesCheckoutSessions $sessions,
        private ?CreatesRefunds $refunds = null,
    ) {}

    public function initiate(PaymentRequest $request): PaymentResult
    {
        $successUrl = $this->requiredOption($request, 'success_url');
        $cancelUrl = $this->requiredOption($request, 'cancel_url');
        $sessionOptions = $request->option('session_options', []);

        if (! is_array($sessionOptions)) {
            throw new InvalidArgumentException('The Stripe session_options payment option must be an array.');
        }

        $metadata = is_array($sessionOptions['metadata'] ?? null)
            ? $sessionOptions['metadata']
            : [];
        $paymentIntentData = is_array($sessionOptions['payment_intent_data'] ?? null)
            ? $sessionOptions['payment_intent_data']
            : [];
        $paymentIntentMetadata = is_array($paymentIntentData['metadata'] ?? null)
            ? $paymentIntentData['metadata']
            : [];

        $parameters = array_replace($sessionOptions, [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $request->order->customer_email,
            'client_reference_id' => (string) $request->order->getKey(),
            'line_items' => $this->lineItems($request),
            'metadata' => array_replace($metadata, [
                'order_id' => (string) $request->order->getKey(),
                'payment_id' => (string) $request->payment->getKey(),
            ]),
            'payment_intent_data' => array_replace($paymentIntentData, [
                'metadata' => array_replace($paymentIntentMetadata, [
                    'order_id' => (string) $request->order->getKey(),
                    'payment_id' => (string) $request->payment->getKey(),
                ]),
            ]),
        ]);

        $session = $this->sessions->create($parameters, [
            'idempotency_key' => 'larasell-payment-'.$request->payment->getKey(),
        ]);

        if ($session->url === null || $session->url === '') {
            throw new RuntimeException('Stripe did not return a Checkout Session URL.');
        }

        return PaymentResult::pending(
            reference: $session->id,
            action: new RedirectPaymentAction($session->url),
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        if ($this->refunds === null) {
            throw new RuntimeException('The Stripe refund client is not configured.');
        }

        $refundOptions = $request->option('refund_options', []);
        $apiOptions = $request->option('api_options', []);

        if (! is_array($refundOptions) || ! is_array($apiOptions)) {
            throw new InvalidArgumentException('Stripe refund_options and api_options must be arrays.');
        }

        if (! is_string($request->payment->reference) || $request->payment->reference === '') {
            throw new RuntimeException('The Stripe payment does not have a Checkout Session reference.');
        }

        $metadata = is_array($refundOptions['metadata'] ?? null)
            ? $refundOptions['metadata']
            : [];
        $parameters = array_replace($refundOptions, [
            'amount' => (int) $request->refund->amount->amount(),
            'metadata' => array_replace($metadata, [
                'payment_id' => (string) $request->payment->getKey(),
                'refund_id' => (string) $request->refund->getKey(),
            ]),
        ]);
        $refund = $this->refunds->create(
            $request->payment->reference,
            $parameters,
            array_replace($apiOptions, [
                'idempotency_key' => 'larasell-refund-'.$request->refund->getKey(),
            ]),
        );

        return match ($refund->status) {
            Refund::STATUS_SUCCEEDED => RefundResult::succeeded($refund->id),
            Refund::STATUS_FAILED => RefundResult::failed(
                $refund->failure_reason ?? 'Stripe reported that the refund failed.',
                $refund->id,
            ),
            Refund::STATUS_CANCELED => RefundResult::cancelled($refund->id),
            default => RefundResult::pending($refund->id),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function lineItems(PaymentRequest $request): array
    {
        $currency = strtolower($request->order->currency->value);
        $items = $request->order->items->map(fn ($item): array => [
            'quantity' => $item->quantity,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => (int) $item->unit_price->amount(),
                'product_data' => [
                    'name' => $item->product_name,
                ],
            ],
        ])->all();

        if ($request->order->getRawOriginal('shipping_price') !== null
            && $request->order->shipping_price->amount() !== '0') {
            $items[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) $request->order->shipping_price->amount(),
                    'product_data' => [
                        'name' => $request->order->shipping_option_name ?? 'Shipping',
                    ],
                ],
            ];
        }

        return $items;
    }

    private function requiredOption(PaymentRequest $request, string $key): string
    {
        $value = $request->option($key);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("The Stripe {$key} payment option is required.");
        }

        return $value;
    }
}
