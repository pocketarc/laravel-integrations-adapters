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
                || ($code === 403 && self::isRateLimitMessage($e->getMessage()))
                || ($code >= 500 && $code < 600);
        }

        return null;
    }

    #[\Override]
    public function retryDelayMs(\Throwable $e, int $attempt, ?int $statusCode): ?int
    {
        if ($e instanceof ApiLimitExceedException) {
            return max($e->getResetTime() - time(), 1) * 1000;
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

        $client = $this->makeClient($integration);

        $checkpointBase = null;
        if ($cursor === null || $cursor === '') {
            $since = Carbon::createFromTimestamp(0);
        } else {
            $parsed = self::parseTimestamp($cursor);
            if ($parsed === null) {
                throw new InvalidArgumentException("GitHubProvider::syncIncremental() received an unparseable cursor: '{$cursor}'.");
            }
            $checkpointBase = $parsed;
            $since = $parsed->copy()->subHour();
        }

        $state = new GitHubSyncState($integration, $checkpointBase);

        $client->issues()->since($since, function (array $issue) use ($integration, $state): void {
            $updatedAt = self::parseTimestamp($issue['updated_at'] ?? null);
            try {
                $issueData = GitHubIssueData::from($issue);
                GitHubIssueSynced::dispatch($integration, $issueData);
                $state->recordSuccess($updatedAt);
            } catch (\Throwable $e) {
                $state->recordFailure($updatedAt);

                Log::error('GitHubProvider: Failed processing issue: '.$e->getMessage(), [
                    'issue_number' => $issue['number'] ?? 'unknown',
                ]);
                GitHubIssueSyncFailed::dispatch($integration, $issue, $e);
            }
        });

        $safeSyncedAt = $this->resolveSyncCursor($state->earliestFailureAt(), $state->failureCount(), $since, $checkpointBase);

        $result = new SyncResult($state->successCount(), $state->failureCount(), $safeSyncedAt, cursor: $safeSyncedAt->toIso8601String());
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

    private static function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d\TH:i:sP', $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function makeClient(Integration $integration): GitHubClient
    {
        return new GitHubClient($integration);
    }

    private static function isRateLimitMessage(string $message): bool
    {
        $lower = mb_strtolower($message);

        return str_contains($lower, 'rate limit')
            || str_contains($lower, 'throttl')
            || str_contains($lower, 'abuse');
    }

    private function resolveSyncCursor(?Carbon $earliestFailureAt, int $failureCount, Carbon $since, ?Carbon $checkpointBase): Carbon
    {
        if ($earliestFailureAt !== null) {
            $candidate = $earliestFailureAt;
        } else {
            // Don't advance cursor past unprocessed failures without timestamps.
            // Add back the 1-hour buffer that syncIncremental subtracted, so repeated
            // failures don't widen the overlap window on each run.
            $candidate = $failureCount > 0 ? $since->copy()->addHour() : Carbon::now();
        }

        // Clamp to the persisted cursor so a failure on an overlap-window item can't
        // regress progress. The item still gets retried on the next run because the
        // 1-hour overlap re-fetches everything from cursor - 1h onward.
        return $checkpointBase !== null && $checkpointBase->isAfter($candidate) ? $checkpointBase : $candidate;
    }
}
