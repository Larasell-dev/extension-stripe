<?php

namespace Larasell\Stripe;

use InvalidArgumentException;
use Larasell\Larasell\Contracts\PaymentProvider;
use Larasell\Larasell\Payments\PaymentRequest;
use Larasell\Larasell\Payments\PaymentResult;
use Larasell\Larasell\Payments\RedirectPaymentAction;
use Larasell\Stripe\Contracts\CreatesCheckoutSessions;
use RuntimeException;

final readonly class StripePaymentProvider implements PaymentProvider
{
    public function __construct(private CreatesCheckoutSessions $sessions) {}

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
