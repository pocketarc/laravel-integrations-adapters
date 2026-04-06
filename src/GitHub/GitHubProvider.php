<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub;

use Github\Exception\ApiLimitExceedException;
use Github\Exception\RuntimeException as GitHubRuntimeException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Adapters\GitHub\Events\GitHubIssueSynced;
use Integrations\Adapters\GitHub\Events\GitHubIssueSyncFailed;
use Integrations\Adapters\GitHub\Events\GitHubSyncCompleted;
use Integrations\Contracts\CustomizesRetry;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\HasIncrementalSync;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\RedactsRequestData;
use Integrations\Models\Integration;
use Integrations\Sync\SyncResult;
use InvalidArgumentException;

class GitHubProvider implements CustomizesRetry, HasHealthCheck, HasIncrementalSync, IntegrationProvider, RedactsRequestData
{
    #[\Override]
    public function isRetryable(\Throwable $e): ?bool
    {
        if ($e instanceof ApiLimitExceedException) {
            return true;
        }

        if ($e instanceof ConnectException) {
            return true;
        }

        if ($e instanceof GitHubRuntimeException) {
            $code = $e->getCode();

            return $code === 429
                || ($code === 403 && str_contains($e->getMessage(), 'throttl'))
                || ($code >= 500 && $code < 600);
        }

        return null;
    }

    #[\Override]
    public function retryDelayMs(\Throwable $e, int $attempt, ?int $statusCode): ?int
    {
        if ($e instanceof ApiLimitExceedException) {
            $delaySeconds = max($e->getResetTime() - time(), 1);

            return min($delaySeconds, 120) * 1000;
        }

        return null;
    }

    #[\Override]
    public function name(): string
    {
        return 'GitHub';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function credentialRules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
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
    #[\Override]
    public function credentialDataClass(): string
    {
        return GitHubCredentials::class;
    }

    /**
     * @return class-string<GitHubMetadata>
     */
    #[\Override]
    public function metadataDataClass(): string
    {
        return GitHubMetadata::class;
    }

    #[\Override]
    public function sync(Integration $integration): SyncResult
    {
        return $this->syncIncremental($integration, null);
    }

    #[\Override]
    public function syncIncremental(Integration $integration, mixed $cursor): SyncResult
    {
        if ($cursor !== null && ! is_string($cursor)) {
            throw new InvalidArgumentException('GitHubProvider::syncIncremental() expects $cursor to be a string or null, got '.get_debug_type($cursor).'.');
        }

        $client = new GitHubClient($integration);
        $since = is_string($cursor) ? Carbon::parse($cursor)->subHour() : Carbon::createFromTimestamp(0);

        $successCount = 0;
        $failureCount = 0;
        $earliestFailureAt = null;

        $client->issues()->since($since, function (array $issue) use ($integration, &$successCount, &$failureCount, &$earliestFailureAt): void {
            try {
                $issueData = GitHubIssueData::from($issue);
                GitHubIssueSynced::dispatch($integration, $issueData);
                $successCount++;
            } catch (\Throwable $e) {
                $failureCount++;
                $updatedAt = array_key_exists('updated_at', $issue) && is_string($issue['updated_at']) ? Carbon::parse($issue['updated_at']) : null;
                if ($updatedAt !== null) {
                    $earliestFailureAt = $earliestFailureAt?->min($updatedAt) ?? $updatedAt;
                }

                Log::error('GitHubProvider: Failed processing issue: '.$e->getMessage(), [
                    'issue_number' => $issue['number'] ?? 'unknown',
                ]);
                GitHubIssueSyncFailed::dispatch($integration, $issue, $e);
            }
        });

        $safeSyncedAt = $this->resolveSyncCursor($earliestFailureAt, $failureCount, $since);

        $result = new SyncResult($successCount, $failureCount, $safeSyncedAt, cursor: $safeSyncedAt->toIso8601String());
        GitHubSyncCompleted::dispatch($integration, $result);

        return $result;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function sensitiveRequestFields(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function sensitiveResponseFields(): array
    {
        return [];
    }

    #[\Override]
    public function defaultSyncInterval(): int
    {
        return 5;
    }

    #[\Override]
    public function defaultRateLimit(): int
    {
        return 60;
    }

    #[\Override]
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

    private function resolveSyncCursor(?Carbon $earliestFailureAt, int $failureCount, Carbon $since): Carbon
    {
        if ($earliestFailureAt !== null) {
            return $earliestFailureAt;
        }

        // Don't advance cursor past unprocessed failures without timestamps.
        // Add back the 1-hour buffer that syncIncremental subtracted, so repeated
        // failures don't widen the overlap window on each run.
        return $failureCount > 0 ? $since->copy()->addHour() : Carbon::now();
    }
}
