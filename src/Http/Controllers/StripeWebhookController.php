<?php

namespace Larasell\Stripe\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Larasell\Stripe\Webhooks\StripeWebhookHandler;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

final readonly class StripeWebhookController
{
    public function __construct(private StripeWebhookHandler $handler) {}

    public function __invoke(Request $request): Response
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                (string) config('larasell-stripe.webhook_secret'),
            );
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return response('Invalid Stripe webhook.', 400);
        }

        $this->handler->handle($event);

        return response('Webhook handled.');
    }
}
