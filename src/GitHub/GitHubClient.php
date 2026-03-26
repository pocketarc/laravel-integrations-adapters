<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub;

use Github\Api\Issue as IssueApi;
use Github\AuthMethod;
use Github\Client as GithubSdkClient;
use Github\Exception\ApiLimitExceedException;
use Github\Exception\RuntimeException;
use Github\ResultPager;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Integrations\Adapters\GitHub\Data\GitHubEventData;
use Integrations\Adapters\GitHub\Enums\GitHubIssueStateReason;
use Integrations\Exceptions\RetriesExhaustedException;
use Integrations\Models\Integration;

class GitHubClient
{
    private GithubSdkClient $sdk;

    private string $owner;

    private string $repo;

    private string $token;

    public function __construct(Integration $integration)
    {
        $credentials = $integration->credentials;
        $metadata = $integration->metadata;

        if (! $credentials instanceof GitHubCredentials || ! $metadata instanceof GitHubMetadata) {
            throw new \RuntimeException('Invalid GitHub integration: credentials or metadata type mismatch.');
        }

        $this->owner = $metadata->owner;
        $this->repo = $metadata->repo;
        $this->token = $credentials->token;

        $this->sdk = new GithubSdkClient;
        $this->sdk->authenticate($this->token, null, AuthMethod::ACCESS_TOKEN);
    }

    public function getSdkClient(): GithubSdkClient
    {
        return $this->sdk;
    }

    /**
     * Create a new GitHub issue.
     *
     * @param  array<string>  $labels
     * @return array<string, mixed>
     */
    public function createIssue(string $title, string $body, array $labels = []): array
    {
        try {
            $params = [
                'title' => $title,
                'body' => $body,
            ];

            if ($labels !== []) {
                $params['labels'] = $labels;
            }

            /** @var array<string, mixed> */
            return $this->executeWithRetry(
                fn (): array => $this->getIssueApi()->create($this->owner, $this->repo, $params)
            );
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'permission to create labels') && $labels !== []) {
                $labelList = implode(', ', array_map(fn (string $l): string => "\"{$l}\"", $labels));
                $code = $e->getCode();
                throw new RuntimeException(
                    $e->getMessage()." Labels requested: [{$labelList}]",
                    is_int($code) ? $code : 0,
                    $e
                );
            }
            report($e);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getIssue(int $issueNumber): ?array
    {
        try {
            /** @var array<string, mixed> */
            return $this->executeWithRetry(
                fn (): array => $this->getIssueApi()->show($this->owner, $this->repo, $issueNumber)
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Get issues updated since a specific time.
     *
     * @param  callable(array<string, mixed>): void  $callback
     */
    public function getIssuesSince(\DateTimeInterface $since, callable $callback): void
    {
        try {
            $page = 1;
            $perPage = 100;

            do {
                /** @var list<array<string, mixed>> $issues */
                $issues = $this->executeWithRetry(fn (): array => $this->getIssueApi()
                    ->configure('full')
                    ->all(
                        $this->owner,
                        $this->repo,
                        [
                            'state' => 'all',
                            'since' => $since->format('c'),
                            'sort' => 'updated',
                            'direction' => 'desc',
                            'per_page' => $perPage,
                            'page' => $page,
                        ]
                    ));

                if ($issues === []) {
                    break;
                }

                foreach ($issues as $issue) {
                    if (isset($issue['pull_request'])) {
                        continue;
                    }

                    $callback($issue);
                }

                $page++;
            } while (count($issues) === $perPage);
        } catch (RetriesExhaustedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            if (config('app.debug') === true) {
                throw $e;
            }
        }
    }

    /**
     * Get all comments for an issue.
     *
     * @param  callable(array<string, mixed>): void  $callback
     */
    public function getIssueComments(int $issueNumber, callable $callback): void
    {
        try {
            $page = 1;
            $perPage = 100;

            do {
                /** @var list<array<string, mixed>> $comments */
                $comments = $this->executeWithRetry(fn (): array => $this->getIssueApi()->comments()
                    ->configure('full')
                    ->all(
                        $this->owner,
                        $this->repo,
                        $issueNumber,
                        [
                            'per_page' => $perPage,
                            'page' => $page,
                        ]
                    ));

                if ($comments === []) {
                    break;
                }

                foreach ($comments as $comment) {
                    $callback($comment);
                }

                $page++;
            } while (count($comments) === $perPage);
        } catch (RetriesExhaustedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            if (config('app.debug') === true) {
                throw $e;
            }
        }
    }

    /**
     * Get timeline events for an issue.
     *
     * @param  callable(GitHubEventData): void  $callback
     */
    public function getIssueTimeline(int $issueNumber, callable $callback): void
    {
        try {
            $pager = new ResultPager($this->sdk, 100);

            /** @var list<array<string, mixed>> $timeline */
            $timeline = $this->executeWithRetry(
                fn (): array => $pager->fetch($this->getIssueApi()->timeline(), 'all', [$this->owner, $this->repo, $issueNumber])
            );

            foreach ($timeline as $event) {
                $callback(GitHubEventData::createFromGitHubResponse($event));
            }

            while ($pager->hasNext()) {
                /** @var list<array<string, mixed>> $timeline */
                $timeline = $this->executeWithRetry(fn (): array => $pager->fetchNext());

                foreach ($timeline as $event) {
                    $callback(GitHubEventData::createFromGitHubResponse($event));
                }
            }
        } catch (RetriesExhaustedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            if (config('app.debug') === true) {
                throw $e;
            }
        }
    }

    /**
     * Download a GitHub asset with authentication.
     */
    public function downloadGitHubAsset(string $url): ?string
    {
        try {
            $headers = [];

            $parsedUrl = parse_url($url);
            $domain = is_array($parsedUrl) && isset($parsedUrl['host']) ? $parsedUrl['host'] : '';

            if (str_ends_with($domain, 'github.com') || str_ends_with($domain, 'githubusercontent.com')) {
                $headers = [
                    'Accept' => 'application/octet-stream',
                    'Authorization' => 'Bearer '.$this->token,
                    'X-GitHub-Api-Version' => '2022-11-28',
                ];
            }

            $response = Http::timeout(120)
                ->withHeaders($headers)
                ->throw()
                ->get($url);

            return $response->body();
        } catch (\Throwable $e) {
            Log::error("GitHubClient: Error downloading asset from {$url}: ".$e->getMessage());

            if (config('app.debug') === true) {
                throw $e;
            }

            return null;
        }
    }

    public function closeIssue(int $issueNumber, ?GitHubIssueStateReason $stateReason = null): bool
    {
        try {
            $params = ['state' => 'closed'];

            if ($stateReason !== null) {
                $params['state_reason'] = $stateReason->value;
            }

            $this->executeWithRetry(
                fn (): array => $this->getIssueApi()->update($this->owner, $this->repo, $issueNumber, $params)
            );

            return true;
        } catch (\Throwable $e) {
            report($e);
            if (config('app.debug') === true) {
                throw $e;
            }

            return false;
        }
    }

    public function reopenIssue(int $issueNumber): bool
    {
        try {
            $this->executeWithRetry(
                fn (): array => $this->getIssueApi()->update($this->owner, $this->repo, $issueNumber, ['state' => 'open'])
            );

            return true;
        } catch (\Throwable $e) {
            report($e);
            if (config('app.debug') === true) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function addComment(int $issueNumber, string $comment): ?array
    {
        try {
            /** @var array<string, mixed> */
            return $this->executeWithRetry(
                fn (): array => $this->getIssueApi()->comments()->create($this->owner, $this->repo, $issueNumber, ['body' => $comment])
            );
        } catch (\Throwable $e) {
            report($e);
            if (config('app.debug') === true) {
                throw $e;
            }

            return null;
        }
    }

    private function getIssueApi(): IssueApi
    {
        $api = $this->sdk->api('issue');
        assert($api instanceof IssueApi);

        return $api;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws \Throwable
     */
    private function executeWithRetry(callable $callback, int $maxRetries = 3): mixed
    {
        $lastException = null;
        $retriesMade = 0;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $callback();
            } catch (ConnectException $e) {
                $lastException = $e;

                if ($attempt < $maxRetries) {
                    $retriesMade++;
                    $delay = min(2 ** $attempt, 60);
                    Log::warning("GitHubClient: Connection error, retry {$attempt}/{$maxRetries} in {$delay}s: {$e->getMessage()}");
                    sleep($delay);

                    continue;
                }

                if ($retriesMade > 0) {
                    throw new RetriesExhaustedException($retriesMade, $e);
                }
                throw $e;
            } catch (ApiLimitExceedException $e) {
                $lastException = $e;

                if ($attempt < $maxRetries) {
                    $retriesMade++;
                    $delay = min(max($e->getResetTime() - time(), 1), 60);
                    Log::warning("GitHubClient: Rate limit exceeded, retry {$attempt}/{$maxRetries} in {$delay}s");
                    sleep($delay);

                    continue;
                }

                if ($retriesMade > 0) {
                    throw new RetriesExhaustedException($retriesMade, $e);
                }
                throw $e;
            } catch (RuntimeException $e) {
                $lastException = $e;
                $statusCode = $e->getCode();

                $isThrottled = $statusCode === 403 && str_contains($e->getMessage(), 'throttl');
                $isRateLimited = $statusCode === 429;
                $isServerError = $statusCode >= 500 && $statusCode < 600;

                if (($isThrottled || $isRateLimited || $isServerError) && $attempt < $maxRetries) {
                    $retriesMade++;
                    $delay = min(2 ** $attempt, 60);
                    Log::warning("GitHubClient: HTTP {$statusCode} error, retry {$attempt}/{$maxRetries} in {$delay}s");
                    sleep($delay);

                    continue;
                }

                if ($retriesMade > 0) {
                    throw new RetriesExhaustedException($retriesMade, $e);
                }
                throw $e;
            }
        }

        throw $lastException ?? new RuntimeException('Retry logic exhausted without result');
    }
}
