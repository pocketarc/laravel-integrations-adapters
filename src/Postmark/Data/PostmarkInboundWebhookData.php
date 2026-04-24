<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Postmark webhook payload for `RecordType: Inbound`. Carries the full
 * incoming email: headers, bodies, attachments. Most consumers only need
 * a few routing fields (From, To, MailboxHash, Subject) plus the body;
 * the Headers, Attachments, FromFull, ToFull, CcFull, etc. live in
 * `$original`.
 *
 * `MailboxHash` is the part of the address after the `+` (Postmark's plus-
 * addressing for routing replies back to the right thread/conversation).
 */
class PostmarkInboundWebhookData extends Data
{
    /**
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly string $MessageID,
        public readonly string $From,
        public readonly string $To,
        public readonly string $Subject,
        public readonly Carbon $Date,
        public readonly string $MailboxHash,
        public readonly ?string $FromName = null,
        public readonly ?string $ReplyTo = null,
        public readonly ?string $TextBody = null,
        public readonly ?string $HtmlBody = null,
        public readonly ?string $StrippedTextReply = null,
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

        // Inbound messages come from the sender's SMTP envelope, so the
        // `Date` header follows RFC 2822 (`Thu, 23 Apr 2026 10:00:00 +0000`)
        // rather than the ISO 8601 Spatie Data expects. Normalise to ISO.
        $date = $properties['Date'] ?? null;
        if (is_string($date) && $date !== '') {
            try {
                $properties['Date'] = Carbon::parse($date)->toIso8601String();
            } catch (\Throwable) {
                // Leave the raw value alone. Spatie will throw a clearer error
                // for genuinely malformed input than us swallowing it here.
            }
        }

        return $properties;
    }
}
