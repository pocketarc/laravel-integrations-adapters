<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Spatie\LaravelData\Data;

/**
 * A single webhook subscription registered with Postmark. The `Triggers`
 * field encodes which event types the endpoint receives (Open, Click,
 * Delivery, Bounce, SpamComplaint, SubscriptionChange) plus per-trigger
 * options like Open's `PostFirstOpenOnly` and Bounce's `IncludeContent`.
 * We surface it as a raw array; consumers that need typed access can
 * inspect `original['Triggers']` and decode their preferred shape.
 */
class PostmarkWebhookEndpointData extends Data
{
    /**
     * @param  array<string, mixed>|null  $HttpAuth
     * @param  array<int, array<string, string>>|null  $HttpHeaders
     * @param  array<string, mixed>|null  $Triggers
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly int $ID,
        public readonly string $Url,
        public readonly string $MessageStream,
        public readonly ?array $HttpAuth = null,
        public readonly ?array $HttpHeaders = null,
        public readonly ?array $Triggers = null,
        public readonly ?array $original = null,
    ) {}

    /**
     * @param  array<mixed>  $properties
     * @return array<mixed>
     */
    #[\Override]
    public static function prepareForPipeline(array $properties): array
    {
        $properties['original'] ??= $properties;

        return $properties;
    }
}
