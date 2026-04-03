<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Models\Integration;
use Integrations\Sync\SyncResult;

class ZendeskSyncCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly SyncResult $result,
    ) {}
}
