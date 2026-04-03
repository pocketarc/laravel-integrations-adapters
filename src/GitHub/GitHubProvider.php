<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Adapters\GitHub\Events\GitHubIssueSynced;
use Integrations\Adapters\GitHub\Events\GitHubIssueSyncFailed;
use Integrations\Adapters\GitHub\Events\GitHubSyncCompleted;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\HasIncrementalSync;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\RedactsRequestData;
use Integrations\Models\Integration;
use Integrations\Sync\SyncResult;

class GitHubProvider implements HasHealthCheck, HasIncrementalSync, IntegrationProvider, RedactsRequestData
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

    public function sync(Integration $integration): SyncResult
    {
        return $this->syncIncremental($integration, null);
    }

    public function syncIncremental(Integration $integration, mixed $cursor): SyncResult
    {
        $client = new GitHubClient($integration);
        $since = is_string($cursor) ? Carbon::parse($cursor)->subHour() : Carbon::createFromTimestamp(0);

        $successCount = 0;
        $failureCount = 0;
        $earliestFailureAt = null;

        $client->getIssuesSince($since, function (array $issue) use ($integration, &$successCount, &$failureCount, &$earliestFailureAt): void {
            try {
                $issueData = GitHubIssueData::createFromGitHubResponse($issue);
                GitHubIssueSynced::dispatch($integration, $issueData);
                $successCount++;
            } catch (\Throwable $e) {
                $failureCount++;
                $updatedAt = isset($issue['updated_at']) && is_string($issue['updated_at']) ? Carbon::parse($issue['updated_at']) : null;
                if ($updatedAt !== null && ($earliestFailureAt === null || $updatedAt->isBefore($earliestFailureAt))) {
                    $earliestFailureAt = $updatedAt;
                }

                Log::error('GitHubProvider: Failed processing issue: '.$e->getMessage(), [
                    'issue_number' => $issue['number'] ?? 'unknown',
                ]);
                GitHubIssueSyncFailed::dispatch($integration, $issue, $e);
            }
        });

        $safeSyncedAt = $earliestFailureAt ?? now();

        $result = new SyncResult($successCount, $failureCount, $safeSyncedAt, cursor: $safeSyncedAt->toIso8601String());
        GitHubSyncCompleted::dispatch($integration, $result);

        return $result;
    }

    /**
     * @return list<string>
     */
    public function sensitiveRequestFields(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function sensitiveResponseFields(): array
    {
        return [];
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
