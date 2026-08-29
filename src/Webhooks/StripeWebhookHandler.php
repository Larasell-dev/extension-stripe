<?php

namespace Larasell\Stripe\Webhooks;

use Illuminate\Database\ConnectionInterface;
use Larasell\Larasell\Enums\PaymentStatus;
use Larasell\Larasell\Enums\RefundStatus;
use Larasell\Larasell\Models\ModelRegistry;
use Larasell\Larasell\Models\Payment;
use Larasell\Larasell\Models\Refund as LarasellRefund;
use Larasell\Larasell\Price;
use Larasell\Stripe\Contracts\ResolvesRefundPayments;
use Larasell\Stripe\Models\StripeWebhookEvent;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Refund;

final readonly class StripeWebhookHandler
{
    public function __construct(
        private ConnectionInterface $database,
        private ModelRegistry $models,
        private ResolvesRefundPayments $refundPayments,
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

            if ($event->data->object instanceof Refund) {
                $this->handleRefund($event->type, $event->data->object);
            }

            $record->update(['processed_at' => now()]);
        });
    }

    private function handleRefund(string $type, Refund $stripeRefund): void
    {
        if (! in_array($type, ['refund.created', 'refund.updated', 'refund.failed'], true)) {
            return;
        }

        $provider = config('larasell-stripe.driver', 'stripe');
        $refundId = (string) ($stripeRefund->metadata->refund_id ?? '');
        $paymentId = (string) ($stripeRefund->metadata->payment_id ?? '');

        /** @var LarasellRefund|null $refund */
        $refund = $this->models->refund->query()
            ->where('provider', $provider)
            ->where('reference', $stripeRefund->id)
            ->first();

        if ($refund === null && $refundId !== '') {
            $refund = $this->models->refund->query()
                ->where('provider', $provider)
                ->whereKey($refundId)
                ->first();
        }

        if ($refund !== null && (
            ($refund->reference !== null && $refund->reference !== $stripeRefund->id)
            || ($paymentId !== '' && (string) $refund->payment_id !== $paymentId)
        )) {
            return;
        }

        if ($refund === null) {
            $paymentId = $paymentId !== '' ? $paymentId : $this->refundPayments->resolve($stripeRefund);
            $amount = $stripeRefund->amount;

            if ($paymentId === null || ! is_int($amount) || $amount <= 0) {
                return;
            }

            /** @var Payment|null $payment */
            $payment = $this->models->payment->query()
                ->where('provider', $provider)
                ->whereKey($paymentId)
                ->first();

            if ($payment === null || $payment->status !== PaymentStatus::Succeeded) {
                return;
            }

            /** @var LarasellRefund $refund */
            $refund = $payment->refunds()->firstOrCreate(
                ['provider' => $provider, 'reference' => $stripeRefund->id],
                ['status' => RefundStatus::Pending, 'amount' => Price::of($amount)],
            );
        }

        if ($refund->reference === null) {
            $refund->update(['reference' => $stripeRefund->id]);
        }

        match ($stripeRefund->status) {
            Refund::STATUS_SUCCEEDED => $this->succeedRefund($refund),
            Refund::STATUS_FAILED => $this->failRefund($refund, $stripeRefund->failure_reason),
            Refund::STATUS_CANCELED => $this->cancelRefund($refund),
            default => null,
        };
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

    private function succeedRefund(LarasellRefund $refund): void
    {
        if (in_array($refund->status, [RefundStatus::Pending, RefundStatus::Succeeded], true)) {
            $refund->markAsSucceeded();
        }
    }

    private function failRefund(LarasellRefund $refund, ?string $reason): void
    {
        if (in_array($refund->status, [RefundStatus::Pending, RefundStatus::Failed], true)) {
            $refund->markAsFailed($reason ?? 'Stripe reported that the refund failed.');
        }
    }

    private function cancelRefund(LarasellRefund $refund): void
    {
        if (in_array($refund->status, [RefundStatus::Pending, RefundStatus::Cancelled], true)) {
            $refund->cancel();
        }
    }
}
