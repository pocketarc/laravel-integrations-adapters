<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Adapters\Postmark\Data\PostmarkDeliveryWebhookData;
use Integrations\Models\Integration;

/**
 * Dispatched alongside PostmarkWebhookReceived when `RecordType: Delivery`.
 * Use this when you only care about successful sends — bounce/spam/inbound
 * listeners get their own typed events.
 */
final class PostmarkDeliveryReceived
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly PostmarkDeliveryWebhookData $delivery,
    ) {}
}
