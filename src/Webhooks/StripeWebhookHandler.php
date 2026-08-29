<?php

namespace Larasell\Stripe\Webhooks;

use Illuminate\Database\ConnectionInterface;
use Larasell\Larasell\Enums\PaymentStatus;
use Larasell\Larasell\Models\ModelRegistry;
use Larasell\Larasell\Models\Payment;
use Larasell\Stripe\Models\StripeWebhookEvent;
use Stripe\Checkout\Session;
use Stripe\Event;

final readonly class StripeWebhookHandler
{
    public function __construct(
        private ConnectionInterface $database,
        private ModelRegistry $models,
    ) {}

    public function handle(Event $event): void
    {
        $this->database->transaction(function () use ($event): void {
            $record = StripeWebhookEvent::query()->firstOrCreate(
                ['provider_event_id' => $event->id],
                ['type' => $event->type],
            );

            if (! $record->wasRecentlyCreated) {
                return;
            }

            if ($event->data->object instanceof Session) {
                $this->handleSession($event->type, $event->data->object);
            }

            $record->update(['processed_at' => now()]);
        });
    }

    private function handleSession(string $type, Session $session): void
    {
        if (! in_array($type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',
        ], true)) {
            return;
        }

        if ($type === 'checkout.session.completed' && $session->payment_status !== 'paid') {
            return;
        }

        /** @var Payment|null $payment */
        $payment = $this->models->payment->query()
            ->where('provider', config('larasell-stripe.driver', 'stripe'))
            ->where('reference', $session->id)
            ->first();

        if ($payment === null || (string) $payment->getKey() !== (string) ($session->metadata->payment_id ?? '')) {
            return;
        }

        match ($type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->succeed($payment),
            'checkout.session.async_payment_failed' => $this->fail($payment),
            'checkout.session.expired' => $this->cancel($payment),
        };
    }

    private function succeed(Payment $payment): void
    {
        if (in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Succeeded], true)) {
            $payment->markAsPaid();
        }
    }

    private function fail(Payment $payment): void
    {
        if (in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed], true)) {
            $payment->markAsFailed('Stripe reported that the payment failed.');
        }
    }

    private function cancel(Payment $payment): void
    {
        if (in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Cancelled], true)) {
            $payment->cancel();
        }
    }
}
