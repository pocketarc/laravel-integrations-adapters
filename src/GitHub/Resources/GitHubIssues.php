<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Resources;

use Github\Exception\RuntimeException;
use Github\ResultPager;
use Integrations\Adapters\GitHub\Data\GitHubEventData;
use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Adapters\GitHub\Enums\GitHubIssueStateReason;
use Integrations\Adapters\GitHub\GitHubResource;
use Integrations\RequestContext;

class GitHubIssues extends GitHubResource
{
    /**
     * Create a new GitHub issue.
     *
     * The optional `$idempotencyKey` is persisted on
     * `integration_requests.idempotency_key` for searchability and our-side
     * dedup downstream, but GitHub itself doesn't natively dedupe by it.
     * Core logs a warning when one is set against a non-`SupportsIdempotency`
     * provider.
     *
     * @param  array<string>  $labels
     */
    public function create(
        string $title,
        string $body,
        array $labels = [],
        ?string $idempotencyKey = null,
    ): GitHubIssueData {
        try {
            $params = [
                'title' => $title,
                'body' => $body,
            ];

            if ($labels !== []) {
                $params['labels'] = $labels;
            }

            /** @var array<string, mixed> $response */
            $response = $this->integration
                ->at("repos/{$this->owner()}/{$this->repo()}/issues")
                ->withData($params)
                ->withIdempotencyKey($idempotencyKey)
                ->post(function (RequestContext $ctx) use ($params): array {
                    $issue = $this->getIssueApi()->create($this->owner(), $this->repo(), $params);
                    $this->reportGitHubMetadata($ctx);

                    return $issue;
                });

            return GitHubIssueData::from($response);
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
    public function get(int $issueNumber): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->executeWithErrorHandling(fn () => $this->integration
            ->at("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}")
            ->get(function (RequestContext $ctx) use ($issueNumber): array {
                $issue = $this->getIssueApi()->show($this->owner(), $this->repo(), $issueNumber);
                $this->reportGitHubMetadata($ctx);

                return $issue;
            }));
    }

    public function close(int $issueNumber, ?GitHubIssueStateReason $stateReason = null): ?GitHubIssueData
    {
        if ($stateReason === GitHubIssueStateReason::Reopened) {
            throw new \DomainException('Cannot close an issue with state_reason "reopened".');
        }

        return $this->executeWithErrorHandling(function () use ($issueNumber, $stateReason): GitHubIssueData {
            $params = ['state' => 'closed'];

            if ($stateReason !== null) {
                $params['state_reason'] = $stateReason->value;
            }

            /** @var array<string, mixed> $response */
            $response = $this->integration
                ->at("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}")
                ->withData($params)
                ->patch(function (RequestContext $ctx) use ($issueNumber, $params): array {
                    $issue = $this->getIssueApi()->update($this->owner(), $this->repo(), $issueNumber, $params);
                    $this->reportGitHubMetadata($ctx);

                    return $issue;
                });

            return GitHubIssueData::from($response);
        });
    }

    public function reopen(int $issueNumber): ?GitHubIssueData
    {
        return $this->executeWithErrorHandling(function () use ($issueNumber): GitHubIssueData {
            /** @var array<string, mixed> $response */
            $response = $this->integration
                ->at("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}")
                ->withData(['state' => 'open'])
                ->patch(function (RequestContext $ctx) use ($issueNumber): array {
                    $issue = $this->getIssueApi()->update($this->owner(), $this->repo(), $issueNumber, ['state' => 'open']);
                    $this->reportGitHubMetadata($ctx);

                    return $issue;
                });

            return GitHubIssueData::from($response);
        });
    }

    /**
     * Get issues updated since a specific time.
     *
     * @param  callable(array<string, mixed>): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function since(\DateTimeInterface $since, callable $callback): void
    {
        $page = 1;
        $perPage = 100;

        do {
            /** @var list<array<string, mixed>> $issues */
            $issues = $this->integration
                ->at("repos/{$this->owner()}/{$this->repo()}/issues?page={$page}")
                ->withData(['since' => $since->format('c'), 'page' => $page])
                ->get(function (RequestContext $ctx) use ($since, $perPage, $page): array {
                    $result = $this->getIssueApi()
                        ->configure('full')
                        ->all(
                            $this->owner(),
                            $this->repo(),
                            [
                                'state' => 'all',
                                'since' => $since->format('c'),
                                'sort' => 'updated',
                                'direction' => 'desc',
                                'per_page' => $perPage,
                                'page' => $page,
                            ]
                        );
                    $this->reportGitHubMetadata($ctx);

                    return $result;
                });

            if ($issues === []) {
                break;
            }

            foreach ($issues as $issue) {
                if (array_key_exists('pull_request', $issue)) {
                    continue;
                }

                $callback($issue);
            }

            $page++;
        } while (count($issues) === $perPage);
    }

    /**
     * Get timeline events for an issue.
     *
     * @param  callable(GitHubEventData): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function timeline(int $issueNumber, callable $callback): void
    {
        $pager = new ResultPager($this->sdk(), 100);
        $page = 1;

        /** @var list<array<string, mixed>> $timeline */
        $timeline = $this->integration
            ->at("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}/timeline?page={$page}")
            ->withData(['issue_number' => $issueNumber, 'page' => $page])
            ->get(function (RequestContext $ctx) use ($pager, $issueNumber): array {
                $result = $pager->fetch($this->getIssueApi()->timeline(), 'all', [$this->owner(), $this->repo(), $issueNumber]);
                $this->reportGitHubMetadata($ctx);

                return $result;
            });

        foreach ($timeline as $event) {
            $callback(GitHubEventData::from($event));
        }

        while ($pager->hasNext()) {
            $page++;

            /** @var list<array<string, mixed>> $timeline */
            $timeline = $this->integration
                ->at("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}/timeline?page={$page}")
                ->withData(['issue_number' => $issueNumber, 'page' => $page])
                ->get(function (RequestContext $ctx) use ($pager): array {
                    $result = $pager->fetchNext();
                    $this->reportGitHubMetadata($ctx);

                    return $result;
                });

            foreach ($timeline as $event) {
                $callback(GitHubEventData::from($event));
            }
        }
    }
}
