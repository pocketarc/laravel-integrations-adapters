<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk;

use Integrations\Adapters\Concerns\HandlesErrors;
use Integrations\Models\Integration;
use Zendesk\API\HttpClient as ZendeskAPI;

abstract class ZendeskResource
{
    use HandlesErrors;

    public function __construct(
        protected readonly Integration $integration,
        protected readonly ZendeskClient $client,
    ) {}

    protected function sdk(): ZendeskAPI
    {
        return $this->client->getSdkClient();
    }
}
