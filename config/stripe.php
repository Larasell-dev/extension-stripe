<?php

return [
    'driver' => 'stripe',
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'webhook_path' => 'larasell/stripe/webhook',
];
