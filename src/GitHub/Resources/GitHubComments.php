<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Resources;

use Integrations\Adapters\GitHub\Data\GitHubCommentData;
use Integrations\Adapters\GitHub\GitHubResource;
use Integrations\RequestContext;

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
                ->get(function (RequestContext $ctx) use ($issueNumber, $perPage, $page): array {
                    $result = $this->getIssueApi()->comments()
                        ->configure('full')
                        ->all(
                            $this->owner(),
                            $this->repo(),
                            $issueNumber,
                            [
                                'per_page' => $perPage,
                                'page' => $page,
                            ]
                        );
                    $this->reportGitHubMetadata($ctx);

                    return $result;
                });

            if ($comments === []) {
                break;
            }

            foreach ($comments as $comment) {
                $callback($comment);
            }

            $page++;
        } while (count($comments) === $perPage);
    }

    public function add(int $issueNumber, string $comment, ?string $idempotencyKey = null): ?GitHubCommentData
    {
        return $this->executeWithErrorHandling(function () use ($issueNumber, $comment, $idempotencyKey): GitHubCommentData {
            /** @var array<string, mixed> $response */
            $response = $this->integration
                ->at("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}/comments")
                ->withData(['body' => $comment])
                ->withIdempotencyKey($idempotencyKey)
                ->post(function (RequestContext $ctx) use ($issueNumber, $comment): array {
                    $result = $this->getIssueApi()->comments()->create($this->owner(), $this->repo(), $issueNumber, ['body' => $comment]);
                    $this->reportGitHubMetadata($ctx);

                    return $result;
                });

            return GitHubCommentData::from($response);
        });
    }
}
