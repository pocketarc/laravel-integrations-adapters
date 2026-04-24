<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Models\Integration;

/**
 * Catch-all event dispatched by PostmarkProvider::handleWebhook for every
 * verified delivery, regardless of `RecordType`. Consumers that want a
 * single sink for audit/log/debug listen here; consumers that care about a
 * specific record type listen for the typed event (PostmarkBounceReceived,
 * PostmarkInboundReceived, etc.) instead. Both fire — generic first, then
 * the typed one.
 */
final class PostmarkWebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly Integration $integration,
        public readonly string $recordType,
        public readonly string $messageId,
        public readonly array $payload,
    ) {}
}
