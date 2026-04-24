<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Carbon;
use Integrations\Adapters\Postmark\Enums\PostmarkBounceType;
use Spatie\LaravelData\Data;

/**
 * Postmark webhook payload for `RecordType: Bounce`. `Inactive` flips to true
 * once Postmark has suppressed the address; `CanActivate` indicates whether
 * the suppression can be cleared via the Bounces API. The bounce ID is a
 * 64-bit integer; PHP int handles it on 64-bit platforms.
 */
class PostmarkBounceWebhookData extends Data
{
    /**
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly int $ID,
        public readonly PostmarkBounceType $Type,
        public readonly string $MessageID,
        public readonly string $MessageStream,
        public readonly string $Email,
        public readonly Carbon $BouncedAt,
        public readonly bool $Inactive,
        public readonly bool $CanActivate,
        public readonly ?string $From = null,
        public readonly ?string $Subject = null,
        public readonly ?string $Tag = null,
        public readonly ?string $Description = null,
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
