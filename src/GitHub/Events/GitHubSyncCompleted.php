<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Models\Integration;
use Integrations\Sync\SyncResult;

class GitHubSyncCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly SyncResult $result,
    ) {}
}
