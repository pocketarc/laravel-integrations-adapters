<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Data;

use Spatie\LaravelData\Data;

class StripeEventData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        /**
         * Raw `data.object` payload — its shape varies by event type. Use
         * StripePaymentIntentData / StripeChargeData / etc. `::from()` at
         * the call site to decode into a typed object when needed.
         *
         * @var array<string, mixed>
         */
        public readonly array $data_object,
        public readonly ?int $created = null,
        public readonly ?string $api_version = null,
        public readonly bool $livemode = false,
        public readonly ?int $pending_webhooks = null,
    ) {}
}
