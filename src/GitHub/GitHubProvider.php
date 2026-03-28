<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub;

use Illuminate\Support\Facades\Http;
use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Adapters\GitHub\Events\GitHubIssueSynced;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Models\Integration;

class GitHubProvider implements HasHealthCheck, HasScheduledSync, IntegrationProvider
{
    public function name(): string
    {
        return 'GitHub';
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialRules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataRules(): array
    {
        return [
            'owner' => ['required', 'string'],
            'repo' => ['required', 'string'],
        ];
    }

    /**
     * @return class-string<GitHubCredentials>
     */
    public function credentialDataClass(): string
    {
        return GitHubCredentials::class;
    }

    /**
     * @return class-string<GitHubMetadata>
     */
    public function metadataDataClass(): string
    {
        return GitHubMetadata::class;
    }

    public function sync(Integration $integration): void
    {
        $client = new GitHubClient($integration);
        $since = $integration->last_synced_at ?? now()->subDay();

        $client->getIssuesSince($since, function (array $issue) use ($integration): void {
            $issueData = GitHubIssueData::createFromGitHubResponse($issue);
            GitHubIssueSynced::dispatch($integration, $issueData);
        });
    }

    public function defaultSyncInterval(): int
    {
        return 5;
    }

    public function defaultRateLimit(): ?int
    {
        return 60;
    }

    public function healthCheck(Integration $integration): bool
    {
        $credentials = $integration->credentials;
        $metadata = $integration->metadata;

        if (! $credentials instanceof GitHubCredentials || ! $metadata instanceof GitHubMetadata) {
            return false;
        }

        try {
            $response = Http::withToken($credentials->token)
                ->withHeaders(['Accept' => 'application/vnd.github.v3+json'])
                ->connectTimeout(5)
                ->timeout(10)
                ->get("https://api.github.com/repos/{$metadata->owner}/{$metadata->repo}");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
