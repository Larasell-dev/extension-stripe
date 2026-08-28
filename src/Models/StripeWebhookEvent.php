<?php

namespace Larasell\Stripe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $provider_event_id
 * @property string $type
 * @property Carbon|null $processed_at
 */
final class StripeWebhookEvent extends Model
{
    protected $table = 'larasell_stripe_webhook_events';

    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
