<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Events;

use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Models\Integration;
use Integrations\Sync\SyncItemEvent;

class GitHubIssueSynced extends SyncItemEvent
{
    public function __construct(
        public readonly Integration $integration,
        public readonly GitHubIssueData $issue,
    ) {}
}
