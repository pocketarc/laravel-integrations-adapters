<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub;

use Github\AuthMethod;
use Github\Client as GithubSdkClient;
use Integrations\Adapters\GitHub\Resources\GitHubAssets;
use Integrations\Adapters\GitHub\Resources\GitHubComments;
use Integrations\Adapters\GitHub\Resources\GitHubIssues;
use Integrations\Models\Integration;
use RuntimeException;

class GitHubClient
{
    private ?GithubSdkClient $sdk;

    private ?string $owner = null;

    private ?string $repo = null;

    private ?string $token = null;

    private bool $booted = false;

    private ?GitHubIssues $issues = null;

    private ?GitHubComments $comments = null;

    private ?GitHubAssets $assets = null;

    public function __construct(
        private readonly Integration $integration,
        ?GithubSdkClient $sdk = null,
    ) {
        $this->sdk = $sdk;
    }

    public function issues(): GitHubIssues
    {
        return $this->issues ??= new GitHubIssues($this->integration, $this);
    }

    public function comments(): GitHubComments
    {
        return $this->comments ??= new GitHubComments($this->integration, $this);
    }

    public function assets(): GitHubAssets
    {
        return $this->assets ??= new GitHubAssets($this->integration, $this);
    }

    public function getSdkClient(): GithubSdkClient
    {
        $this->boot();

        if ($this->sdk === null) {
            throw new RuntimeException('GitHub SDK client not initialized.');
        }

        return $this->sdk;
    }

    public function getOwner(): string
    {
        $this->boot();

        return $this->owner ?? throw new RuntimeException('GitHub owner not initialized.');
    }

    public function getRepo(): string
    {
        $this->boot();

        return $this->repo ?? throw new RuntimeException('GitHub repo not initialized.');
    }

    public function getToken(): string
    {
        $this->boot();

        return $this->token ?? throw new RuntimeException('GitHub token not initialized.');
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $credentials = $this->integration->credentials;
        $metadata = $this->integration->metadata;

        if (! $credentials instanceof GitHubCredentials) {
            throw new RuntimeException(sprintf(
                'GitHub integration (id=%s): credentials must be %s, got %s.',
                $this->integration->id ?? 'null',
                GitHubCredentials::class,
                get_debug_type($credentials),
            ));
        }

        if (! $metadata instanceof GitHubMetadata) {
            throw new RuntimeException(sprintf(
                'GitHub integration (id=%s): metadata must be %s, got %s.',
                $this->integration->id ?? 'null',
                GitHubMetadata::class,
                get_debug_type($metadata),
            ));
        }

        $this->owner = $metadata->owner;
        $this->repo = $metadata->repo;
        $this->token = $credentials->token;

        if ($this->sdk === null) {
            $this->sdk = new GithubSdkClient;
            $this->sdk->authenticate($this->token, null, AuthMethod::ACCESS_TOKEN);
        }

        $this->booted = true;
    }
}
