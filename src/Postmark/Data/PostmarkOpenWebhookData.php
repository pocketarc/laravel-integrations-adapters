<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Postmark webhook payload for `RecordType: Open`. Postmark fires this for
 * every open by default; set `PostFirstOpenOnly` on the server to limit it
 * to the first open per recipient (`FirstOpen` will then be reliably true).
 * Client/OS/Geo blocks live in `$original` so consumers can dig in without
 * us flattening or reshaping them.
 */
class PostmarkOpenWebhookData extends Data
{
    /**
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly string $MessageID,
        public readonly string $Recipient,
        public readonly Carbon $ReceivedAt,
        public readonly bool $FirstOpen,
        public readonly string $MessageStream,
        public readonly int $ReadSeconds,
        public readonly ?string $Tag = null,
        public readonly ?string $UserAgent = null,
        public readonly ?string $Platform = null,
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
