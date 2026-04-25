<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Resources;

use Integrations\Adapters\GitHub\Data\GitHubCommentData;
use Integrations\Adapters\GitHub\GitHubResource;

class GitHubComments extends GitHubResource
{
    /**
     * Get all comments for an issue.
     *
     * @param  callable(array<string, mixed>): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function list(int $issueNumber, callable $callback): void
    {
        $page = 1;
        $perPage = 100;

        do {
            /** @var list<array<string, mixed>> $comments */
            $comments = $this->integration
                ->at("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}/comments?page={$page}")
                ->withData(['issue_number' => $issueNumber, 'page' => $page])
                ->get(fn (): array => $this->getIssueApi()->comments()
                    ->configure('full')
                    ->all(
                        $this->owner(),
                        $this->repo(),
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
    }

    public function add(int $issueNumber, string $comment): ?GitHubCommentData
    {
        return $this->executeWithErrorHandling(function () use ($issueNumber, $comment): GitHubCommentData {
            /** @var array<string, mixed> $response */
            $response = $this->integration
                ->at("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}/comments")
                ->withData(['body' => $comment])
                ->post(fn (): array => $this->getIssueApi()->comments()->create($this->owner(), $this->repo(), $issueNumber, ['body' => $comment]));

            return GitHubCommentData::from($response);
        });
    }
}
