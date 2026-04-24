<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Adapters\Postmark\Data\PostmarkClickWebhookData;
use Integrations\Models\Integration;

final class PostmarkClickReceived
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly PostmarkClickWebhookData $click,
    ) {}
}
