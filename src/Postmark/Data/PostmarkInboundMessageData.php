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
 *
 * Postmark returns `To` and `Cc` as raw header strings on the inbound
 * search endpoint (the parsed `ToFull`/`CcFull` arrays are only on the
 * per-message details endpoint). We keep the two separate; consumers
 * who need structured addresses fetch the details.
 */
class PostmarkInboundMessageData extends Data
{
    /**
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
        public readonly ?string $To = null,
        public readonly ?string $Cc = null,
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
