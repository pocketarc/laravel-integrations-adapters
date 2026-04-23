<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Data;

use Spatie\LaravelData\Data;

class StripeWebhookEndpointData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly string $status,
        /** @var list<string> */
        public readonly array $enabled_events,
        public readonly ?string $description = null,
        public readonly ?string $secret = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $metadata = null,
        public readonly ?int $created = null,
    ) {}
}
