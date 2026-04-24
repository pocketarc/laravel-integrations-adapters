<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Summary entry for an outbound message in the GET /messages/outbound list.
 * `Status` is one of "Sent", "Queued", "Bounced", "Failed", etc. The full
 * recipient blocks (To/Cc/Bcc), HtmlBody, TextBody, and Tracking details
 * are only available via the per-message details endpoint.
 */
class PostmarkOutboundMessageData extends Data
{
    /**
     * @param  array<int, mixed>|null  $To
     * @param  array<int, mixed>|null  $Cc
     * @param  array<int, mixed>|null  $Bcc
     * @param  array<int, string>|null  $Recipients
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly string $MessageID,
        public readonly string $From,
        public readonly string $Subject,
        public readonly string $Status,
        public readonly Carbon $ReceivedAt,
        public readonly string $MessageStream,
        public readonly ?string $Tag = null,
        public readonly ?array $To = null,
        public readonly ?array $Cc = null,
        public readonly ?array $Bcc = null,
        public readonly ?array $Recipients = null,
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
