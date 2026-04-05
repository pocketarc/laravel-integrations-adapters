<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub;

use Github\Api\Issue as IssueApi;
use Github\Client as GithubSdkClient;
use Github\Exception\ApiLimitExceedException;
use Github\Exception\RuntimeException;
use GuzzleHttp\Exception\ConnectException;
use Integrations\Adapters\Concerns\RetriesRequests;
use Integrations\Models\Integration;

abstract class GitHubResource
{
    use RetriesRequests;

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
        assert($api instanceof IssueApi);

        return $api;
    }

    /**
     * @return array{int, string}|null
     */
    #[\Override]
    protected function getRetryDelay(\Throwable $e, int $attempt): ?array
    {
        if ($e instanceof ConnectException) {
            return [(int) min(2 ** $attempt, 60), "Connection error: {$e->getMessage()}"];
        }

        if ($e instanceof ApiLimitExceedException) {
            return [min(max($e->getResetTime() - time(), 1), 60), 'Rate limit exceeded'];
        }

        if ($e instanceof RuntimeException && self::isRetryableHttpError($e)) {
            return [(int) min(2 ** $attempt, 60), "HTTP {$e->getCode()} error"];
        }

        return null;
    }

    private static function isRetryableHttpError(RuntimeException $e): bool
    {
        $statusCode = $e->getCode();

        return ($statusCode === 403 && str_contains($e->getMessage(), 'throttl'))
            || $statusCode === 429
            || ($statusCode >= 500 && $statusCode < 600);
    }
}
