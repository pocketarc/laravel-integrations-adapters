<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Postmark webhook payload for `RecordType: Click`. `OriginalLink` is the
 * destination URL as it appears in the email (after Postmark unwraps its
 * tracking redirect); `ClickLocation` distinguishes HTML body vs text body
 * vs header clicks. As with opens, Client/OS/Geo blocks stay in `$original`.
 */
class PostmarkClickWebhookData extends Data
{
    /**
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly string $MessageID,
        public readonly string $Recipient,
        public readonly Carbon $ReceivedAt,
        public readonly string $MessageStream,
        public readonly string $OriginalLink,
        public readonly string $ClickLocation,
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
