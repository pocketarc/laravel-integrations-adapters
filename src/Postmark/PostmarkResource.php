<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark;

use Integrations\Adapters\Concerns\HandlesErrors;
use Integrations\Models\Integration;
use Postmark\PostmarkClient as PostmarkSdkClient;

abstract class PostmarkResource
{
    use HandlesErrors;

    public function __construct(
        protected readonly Integration $integration,
        protected readonly PostmarkClient $client,
    ) {}

    protected function sdk(): PostmarkSdkClient
    {
        return $this->client->getSdkClient();
    }
}
