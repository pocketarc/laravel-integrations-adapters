<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub;

use Spatie\LaravelData\Data;

class GitHubMetadata extends Data
{
    public function __construct(
        public readonly string $owner,
        public readonly string $repo,
    ) {}
}
