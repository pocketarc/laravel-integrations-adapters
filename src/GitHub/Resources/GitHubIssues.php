<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Resources;

use Github\Exception\RuntimeException;
use Github\ResultPager;
use Integrations\Adapters\GitHub\Data\GitHubEventData;
use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Adapters\GitHub\Enums\GitHubIssueStateReason;
use Integrations\Adapters\GitHub\GitHubResource;

class GitHubIssues extends GitHubResource
{
    /**
     * Create a new GitHub issue.
     *
     * @param  array<string>  $labels
     */
    public function create(string $title, string $body, array $labels = []): GitHubIssueData
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
            $response = $this->integration
                ->to("repos/{$this->owner()}/{$this->repo()}/issues")
                ->withData($params)
                ->post(fn () => $this->executeWithRetry(
                    fn (): array => $this->getIssueApi()->create($this->owner(), $this->repo(), $params)
                ));

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
    public function get(int $issueNumber): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->executeWithErrorHandling(fn () => $this->integration
            ->to("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}")
            ->get(fn () => $this->executeWithRetry(
                fn (): array => $this->getIssueApi()->show($this->owner(), $this->repo(), $issueNumber)
            )));
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
                ->to("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}")
                ->withData($params)
                ->patch(fn () => $this->executeWithRetry(
                    fn (): array => $this->getIssueApi()->update($this->owner(), $this->repo(), $issueNumber, $params)
                ));

            return GitHubIssueData::createFromGitHubResponse($response);
        });
    }

    public function reopen(int $issueNumber): ?GitHubIssueData
    {
        return $this->executeWithErrorHandling(function () use ($issueNumber): GitHubIssueData {
            /** @var array<string, mixed> $response */
            $response = $this->integration
                ->to("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}")
                ->withData(['state' => 'open'])
                ->patch(fn () => $this->executeWithRetry(
                    fn (): array => $this->getIssueApi()->update($this->owner(), $this->repo(), $issueNumber, ['state' => 'open'])
                ));

            return GitHubIssueData::createFromGitHubResponse($response);
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
                ->to("repos/{$this->owner()}/{$this->repo()}/issues?page={$page}")
                ->withData(['since' => $since->format('c'), 'page' => $page])
                ->get(fn () => $this->executeWithRetry(fn (): array => $this->getIssueApi()
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
                    )));

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
            ->to("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}/timeline?page={$page}")
            ->withData(['issue_number' => $issueNumber, 'page' => $page])
            ->get(fn () => $this->executeWithRetry(
                fn (): array => $pager->fetch($this->getIssueApi()->timeline(), 'all', [$this->owner(), $this->repo(), $issueNumber])
            ));

        foreach ($timeline as $event) {
            $callback(GitHubEventData::createFromGitHubResponse($event));
        }

        while ($pager->hasNext()) {
            $page++;

            /** @var list<array<string, mixed>> $timeline */
            $timeline = $this->integration
                ->to("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}/timeline?page={$page}")
                ->withData(['issue_number' => $issueNumber, 'page' => $page])
                ->get(fn () => $this->executeWithRetry(fn (): array => $pager->fetchNext()));

            foreach ($timeline as $event) {
                $callback(GitHubEventData::createFromGitHubResponse($event));
            }
        }
    }
}
