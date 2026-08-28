<?php

use Illuminate\Support\Facades\Route;
use Larasell\Stripe\Http\Controllers\StripeWebhookController;

Route::post(config('larasell-stripe.webhook_path'), StripeWebhookController::class)
    ->name('larasell.stripe.webhook');
