<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Postmark webhook payload for `RecordType: Delivery`. Property names mirror
 * the API exactly; the full untouched payload is in `$original` for fields
 * we don't surface (Metadata, ServerID, RecordType).
 */
class PostmarkDeliveryWebhookData extends Data
{
    /**
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly string $MessageID,
        public readonly string $Recipient,
        public readonly Carbon $DeliveredAt,
        public readonly string $MessageStream,
        public readonly ?string $Tag = null,
        public readonly ?string $Details = null,
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
