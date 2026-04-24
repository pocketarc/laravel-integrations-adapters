<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Postmark webhook payload for `RecordType: SpamComplaint`. The shape mirrors
 * a bounce — Postmark treats spam complaints as bounce-type 100 internally —
 * but it ships its own RecordType so consumers can react differently
 * (suppression vs reputation tracking).
 */
class PostmarkSpamComplaintWebhookData extends Data
{
    /**
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly int $ID,
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
