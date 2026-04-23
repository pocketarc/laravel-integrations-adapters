<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe;

use Spatie\LaravelData\Data;

/**
 * Credentials for a Stripe integration. The API secret signs server-side
 * calls; the webhook secret authenticates inbound events. The publishable
 * key is kept here for convenience (the browser uses it via an env var) so
 * that rotating it doesn't fragment across two systems.
 *
 * Live vs test mode is implicit in the key prefix (sk_live_... vs
 * sk_test_...); we don't store it separately.
 */
class StripeCredentials extends Data
{
    public function __construct(
        public readonly string $api_key,
        public readonly string $webhook_secret,
        public readonly ?string $publishable_key = null,
    ) {}
}
