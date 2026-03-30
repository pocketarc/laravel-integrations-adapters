<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Models\Integration;

class GitHubIssueSyncFailed
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $rawIssue
     */
    public function __construct(
        public readonly Integration $integration,
        public readonly array $rawIssue,
        public readonly \Throwable $exception,
    ) {}
}
