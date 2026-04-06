<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub;

use Github\Api\Issue as IssueApi;
use Github\Client as GithubSdkClient;
use Integrations\Adapters\Concerns\HandlesErrors;
use Integrations\Models\Integration;

abstract class GitHubResource
{
    use HandlesErrors;

    public function __construct(
        protected readonly Integration $integration,
        protected readonly GitHubClient $client,
    ) {}

    protected function sdk(): GithubSdkClient
    {
        return $this->client->getSdkClient();
    }

    protected function owner(): string
    {
        return $this->client->getOwner();
    }

    protected function repo(): string
    {
        return $this->client->getRepo();
    }

    protected function token(): string
    {
        return $this->client->getToken();
    }

    protected function getIssueApi(): IssueApi
    {
        $api = $this->sdk()->api('issue');
        if (! $api instanceof IssueApi) {
            throw new \RuntimeException('Expected IssueApi, got '.get_debug_type($api));
        }

        return $api;
    }
}
