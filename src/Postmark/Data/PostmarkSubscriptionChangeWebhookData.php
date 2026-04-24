<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Postmark webhook payload for `RecordType: SubscriptionChange`. Fired when
 * a recipient is added to or removed from a stream's suppression list, by
 * either Postmark itself (e.g. after a hard bounce) or your code via the
 * Suppressions API. `Origin` indicates who initiated the change
 * ("Recipient", "Customer", "Admin"); `SuppressionReason` is null for
 * reactivations.
 */
class PostmarkSubscriptionChangeWebhookData extends Data
{
    /**
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly string $MessageID,
        public readonly string $MessageStream,
        public readonly string $Recipient,
        public readonly Carbon $ChangedAt,
        public readonly string $Origin,
        public readonly bool $SuppressSending,
        public readonly ?string $SuppressionReason = null,
        public readonly ?string $Tag = null,
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
