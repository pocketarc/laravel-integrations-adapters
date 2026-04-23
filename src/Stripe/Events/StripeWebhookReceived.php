<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Models\Integration;

/**
 * Emitted by StripeProvider::handleWebhook after signature verification.
 * Consumers listen for this event and route by `$eventType` to whatever
 * Action owns the downstream side-effects. The raw `$payload` array is
 * exactly what Stripe sent; typed access is available via the matching
 * resource's Data class (e.g. StripePaymentIntentData::from($payload['data']['object'])).
 *
 * Kept deliberately Stripe-agnostic at the event boundary: a future
 * cashier-gingerbread backend can emit the same event shape without
 * importing \Stripe\Event into consumer code.
 */
final class StripeWebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly Integration $integration,
        public readonly string $eventType,
        public readonly string $eventId,
        public readonly array $payload,
    ) {}
}
