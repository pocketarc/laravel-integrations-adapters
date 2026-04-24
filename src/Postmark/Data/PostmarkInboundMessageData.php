<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Summary entry for an inbound message in the GET /messages/inbound list.
 * `Status` reflects whether Postmark accepted, blocked, or queued the
 * message; `MailboxHash` is the part of the recipient address after `+`,
 * useful for routing replies back to a thread/conversation.
 */
class PostmarkInboundMessageData extends Data
{
    /**
     * @param  array<int, mixed>|null  $To
     * @param  array<int, mixed>|null  $Cc
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly string $MessageID,
        public readonly string $From,
        public readonly string $Subject,
        public readonly string $Status,
        public readonly Carbon $ReceivedAt,
        public readonly string $MailboxHash,
        public readonly ?string $FromName = null,
        public readonly ?string $Tag = null,
        public readonly ?array $To = null,
        public readonly ?array $Cc = null,
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
