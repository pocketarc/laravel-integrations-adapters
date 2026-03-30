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
use Integrations\Adapters\Concerns\RetriesRequests;
use Integrations\Adapters\GitHub\Data\GitHubCommentData;
use Integrations\Adapters\GitHub\Data\GitHubEventData;
use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Adapters\GitHub\Enums\GitHubIssueStateReason;
use Integrations\Exceptions\RetriesExhaustedException;
use Integrations\Models\Integration;

class GitHubClient
{
    use RetriesRequests;

    private Integration $integration;

    private GithubSdkClient $sdk;

    private string $owner;

    private string $repo;

    private string $token;

    public function __construct(Integration $integration, ?GithubSdkClient $sdk = null)
    {
        $credentials = $integration->credentials;
        $metadata = $integration->metadata;

        if (! $credentials instanceof GitHubCredentials || ! $metadata instanceof GitHubMetadata) {
            throw new \RuntimeException('Invalid GitHub integration: credentials or metadata type mismatch.');
        }

        $this->integration = $integration;
        $this->owner = $metadata->owner;
        $this->repo = $metadata->repo;
        $this->token = $credentials->token;

        $this->sdk = $sdk ?? new GithubSdkClient;
        if ($sdk === null) {
            $this->sdk->authenticate($this->token, null, AuthMethod::ACCESS_TOKEN);
        }
    }

    public function getSdkClient(): GithubSdkClient
    {
        return $this->sdk;
    }

    /**
     * Create a new GitHub issue.
     *
     * @param  array<string>  $labels
     */
    public function createIssue(string $title, string $body, array $labels = []): GitHubIssueData
    {
        try {
            $params = [
                'title' => $title,
                'body' => $body,
            ];

            if ($labels !== []) {
                $params['labels'] = $labels;
            }

            /** @var array<string, mixed> $response */
            $response = $this->integration->request(
                endpoint: "repos/{$this->owner}/{$this->repo}/issues",
                method: 'POST',
                callback: fn () => $this->executeWithRetry(
                    fn (): array => $this->getIssueApi()->create($this->owner, $this->repo, $params)
                ),
                requestData: $params,
            );

            return GitHubIssueData::createFromGitHubResponse($response);
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
            return $this->integration->request(
                endpoint: "repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}",
                method: 'GET',
                callback: fn () => $this->executeWithRetry(
                    fn (): array => $this->getIssueApi()->show($this->owner, $this->repo, $issueNumber)
                ),
            );
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                throw $e;
            }

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
                $issues = $this->integration->request(
                    endpoint: "repos/{$this->owner}/{$this->repo}/issues?page={$page}",
                    method: 'GET',
                    callback: fn () => $this->executeWithRetry(fn (): array => $this->getIssueApi()
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
                        )),
                    requestData: ['since' => $since->format('c'), 'page' => $page],
                );

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
                $comments = $this->integration->request(
                    endpoint: "repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/comments?page={$page}",
                    method: 'GET',
                    callback: fn () => $this->executeWithRetry(fn (): array => $this->getIssueApi()->comments()
                        ->configure('full')
                        ->all(
                            $this->owner,
                            $this->repo,
                            $issueNumber,
                            [
                                'per_page' => $perPage,
                                'page' => $page,
                            ]
                        )),
                    requestData: ['issue_number' => $issueNumber, 'page' => $page],
                );

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
            $page = 1;

            /** @var list<array<string, mixed>> $timeline */
            $timeline = $this->integration->request(
                endpoint: "repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/timeline?page={$page}",
                method: 'GET',
                callback: fn () => $this->executeWithRetry(
                    fn (): array => $pager->fetch($this->getIssueApi()->timeline(), 'all', [$this->owner, $this->repo, $issueNumber])
                ),
                requestData: ['issue_number' => $issueNumber, 'page' => $page],
            );

            foreach ($timeline as $event) {
                $callback(GitHubEventData::createFromGitHubResponse($event));
            }

            while ($pager->hasNext()) {
                $page++;

                /** @var list<array<string, mixed>> $timeline */
                $timeline = $this->integration->request(
                    endpoint: "repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/timeline?page={$page}",
                    method: 'GET',
                    callback: fn () => $this->executeWithRetry(fn (): array => $pager->fetchNext()),
                    requestData: ['issue_number' => $issueNumber, 'page' => $page],
                );

                foreach ($timeline as $event) {
                    $callback(GitHubEventData::createFromGitHubResponse($event));
                }
            }
        } catch (RetriesExhaustedException $e) {
            throw $e;
        } catch (\Throwable $e) {
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

            if ($domain === 'github.com' || str_ends_with($domain, '.github.com')
                || $domain === 'githubusercontent.com' || str_ends_with($domain, '.githubusercontent.com')) {
                $headers = [
                    'Accept' => 'application/octet-stream',
                    'Authorization' => 'Bearer '.$this->token,
                    'X-GitHub-Api-Version' => '2022-11-28',
                ];
            }

            $result = $this->integration->request(
                endpoint: $url,
                method: 'GET',
                callback: fn () => Http::timeout(120)
                    ->withHeaders($headers)
                    ->throw()
                    ->get($url),
            );

            return is_string($result) ? $result : null;
        } catch (\Throwable $e) {
            Log::error("GitHubClient: Error downloading asset from {$url}: ".$e->getMessage());

            if (config('app.debug') === true) {
                throw $e;
            }

            return null;
        }
    }

    public function closeIssue(int $issueNumber, ?GitHubIssueStateReason $stateReason = null): ?GitHubIssueData
    {
        if ($stateReason === GitHubIssueStateReason::Reopened) {
            throw new \DomainException('Cannot close an issue with state_reason "reopened".');
        }

        try {
            $params = ['state' => 'closed'];

            if ($stateReason !== null) {
                $params['state_reason'] = $stateReason->value;
            }

            /** @var array<string, mixed> $response */
            $response = $this->integration->request(
                endpoint: "repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}",
                method: 'PATCH',
                callback: fn () => $this->executeWithRetry(
                    fn (): array => $this->getIssueApi()->update($this->owner, $this->repo, $issueNumber, $params)
                ),
                requestData: $params,
            );

            return GitHubIssueData::createFromGitHubResponse($response);
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                throw $e;
            }

            return null;
        }
    }

    public function reopenIssue(int $issueNumber): ?GitHubIssueData
    {
        try {
            /** @var array<string, mixed> $response */
            $response = $this->integration->request(
                endpoint: "repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}",
                method: 'PATCH',
                callback: fn () => $this->executeWithRetry(
                    fn (): array => $this->getIssueApi()->update($this->owner, $this->repo, $issueNumber, ['state' => 'open'])
                ),
                requestData: ['state' => 'open'],
            );

            return GitHubIssueData::createFromGitHubResponse($response);
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                throw $e;
            }

            return null;
        }
    }

    public function addComment(int $issueNumber, string $comment): ?GitHubCommentData
    {
        try {
            /** @var array<string, mixed> $response */
            $response = $this->integration->request(
                endpoint: "repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/comments",
                method: 'POST',
                callback: fn () => $this->executeWithRetry(
                    fn (): array => $this->getIssueApi()->comments()->create($this->owner, $this->repo, $issueNumber, ['body' => $comment])
                ),
                requestData: ['body' => $comment],
            );

            return GitHubCommentData::createFromGitHubResponse($response);
        } catch (\Throwable $e) {
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
     * @return array{int, string}|null
     */
    protected function getRetryDelay(\Throwable $e, int $attempt): ?array
    {
        if ($e instanceof ConnectException) {
            return [(int) min(2 ** $attempt, 60), "Connection error: {$e->getMessage()}"];
        }

        if ($e instanceof ApiLimitExceedException) {
            return [min(max($e->getResetTime() - time(), 1), 60), 'Rate limit exceeded'];
        }

        if ($e instanceof RuntimeException) {
            $statusCode = $e->getCode();
            $isThrottled = $statusCode === 403 && str_contains($e->getMessage(), 'throttl');
            $isRateLimited = $statusCode === 429;
            $isServerError = $statusCode >= 500 && $statusCode < 600;

            if ($isThrottled || $isRateLimited || $isServerError) {
                return [(int) min(2 ** $attempt, 60), "HTTP {$statusCode} error"];
            }
        }

        return null;
    }
}
