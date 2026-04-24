<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Carbon;
use Integrations\Adapters\Postmark\Enums\PostmarkBounceType;
use Spatie\LaravelData\Data;

/**
 * A single bounce as returned by the Bounces API. Same field set as the
 * webhook bounce payload — Postmark deliberately matches the two — but
 * we keep them as separate Data classes so changes to the webhook
 * representation can't break the API one and vice versa.
 */
class PostmarkBounceData extends Data
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
        public readonly bool $DumpAvailable = false,
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
