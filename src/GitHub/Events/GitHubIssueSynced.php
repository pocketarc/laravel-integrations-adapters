<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Models\Integration;

class GitHubIssueSynced
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly GitHubIssueData $issue,
    ) {}
}
