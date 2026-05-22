<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub;

use Github\Exception\ApiLimitExceedException;
use Github\Exception\RuntimeException as GitHubRuntimeException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Adapters\GitHub\Events\GitHubIssueSynced;
use Integrations\Concerns\ReducesCheckpointsByMax;
use Integrations\Contracts\CustomizesRetry;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\HasIncrementalSync;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\RedactsRequestData;
use Integrations\Models\Integration;
use Integrations\RateLimit;
use Integrations\Sync\SyncSession;
use InvalidArgumentException;

class GitHubProvider implements CustomizesRetry, HasHealthCheck, HasIncrementalSync, IntegrationProvider, RedactsRequestData
{
    use ReducesCheckpointsByMax;

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
    public function sync(Integration $integration, SyncSession $session): void
    {
        // A full re-sync ignores the cursor and enumerates from the epoch.
        // The framework only calls this for non-incremental providers; for
        // GitHub it's a fallback, since the provider is HasIncrementalSync.
        $this->enumerate($integration, $session, Carbon::createFromTimestamp(0));
    }

    #[\Override]
    public function syncIncremental(Integration $integration, SyncSession $session): void
    {
        $cursor = $session->cursor();

        if ($cursor === null || $cursor === '') {
            $since = Carbon::createFromTimestamp(0);
        } else {
            if (! is_string($cursor)) {
                throw new InvalidArgumentException('GitHubProvider::syncIncremental() expects the cursor to be a string or null, got '.get_debug_type($cursor).'.');
            }

            $parsed = self::parseTimestamp($cursor);
            if ($parsed === null) {
                throw new InvalidArgumentException("GitHubProvider::syncIncremental() received an unparseable cursor: '{$cursor}'.");
            }

            // Subtract a 1-hour overlap buffer to catch issues updated between
            // runs. The framework's cursor advance is monotonic, so
            // re-presenting items inside that window can't regress progress.
            $since = $parsed->subHour();
        }

        $this->enumerate($integration, $session, $since);
    }

    private function enumerate(Integration $integration, SyncSession $session, Carbon $since): void
    {
        $client = $this->makeClient($integration);

        $client->issues()->since($since, function (array $issue) use ($integration, $session): void {
            $issueData = GitHubIssueData::from($issue);
            $updatedAt = self::parseTimestamp($issue['updated_at'] ?? null);

            $session->dispatch(
                new GitHubIssueSynced($integration, $issueData),
                checkpointValue: $updatedAt?->toIso8601String(),
                externalId: array_key_exists('number', $issue) && (is_int($issue['number']) || is_string($issue['number']))
                    ? (string) $issue['number']
                    : null,
            );
        });
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
    public function defaultRateLimit(): RateLimit
    {
        // GitHub's authenticated REST budget: 5,000 requests/hour, a fixed
        // window that resets at X-RateLimit-Reset.
        return RateLimit::perHour(5000);
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
}
