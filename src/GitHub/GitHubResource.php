<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub;

use Carbon\CarbonImmutable;
use Github\Api\Issue as IssueApi;
use Github\Client as GithubSdkClient;
use Integrations\Adapters\Concerns\HandlesErrors;
use Integrations\Models\Integration;
use Integrations\RequestContext;
use RuntimeException;

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
            throw new RuntimeException('Expected IssueApi, got '.get_debug_type($api));
        }

        return $api;
    }

    /**
     * Pull what we can out of GitHub's last response (the request ID, plus
     * the rate-limit header set when present) and feed it back to core via
     * the RequestContext. GitHub doesn't have native idempotency dedup, but
     * it's chatty about rate limits — and `X-GitHub-Request-Id` is what
     * GitHub's support team asks for first.
     *
     * Adapter resource methods should call this right after the SDK call
     * returns inside the closure passed to `Integration::request()`.
     */
    protected function reportGitHubMetadata(RequestContext $ctx): void
    {
        $last = $this->sdk()->getLastResponse();
        if ($last === null) {
            return;
        }

        $requestId = $last->getHeaderLine('X-GitHub-Request-Id');
        $rawRemaining = $last->getHeaderLine('X-RateLimit-Remaining');
        $rawReset = $last->getHeaderLine('X-RateLimit-Reset');

        $remaining = is_numeric($rawRemaining) ? (int) $rawRemaining : null;
        $resetAt = is_numeric($rawReset) ? CarbonImmutable::createFromTimestamp((int) $rawReset) : null;

        $ctx->reportResponseMetadata(
            providerRequestId: $requestId !== '' ? $requestId : null,
            rateLimitRemaining: $remaining,
            rateLimitResetAt: $resetAt,
        );
    }
}
