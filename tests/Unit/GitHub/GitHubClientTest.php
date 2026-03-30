<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\GitHub;

use Github\AuthMethod;
use Github\Client as GithubSdkClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockHttpClient;
use Integrations\Adapters\GitHub\Data\GitHubCommentData;
use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Adapters\GitHub\GitHubClient;
use Integrations\Adapters\GitHub\GitHubProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Testing\CreatesIntegration;

class GitHubClientTest extends TestCase
{
    use CreatesIntegration;

    private function createClient(MockHttpClient $mockHttp): GitHubClient
    {
        $integration = $this->createIntegration(
            providerKey: 'github',
            providerClass: GitHubProvider::class,
            credentials: ['token' => 'ghp_fake123'],
            metadata: ['owner' => 'acme', 'repo' => 'widgets'],
        );

        $builder = new Builder($mockHttp);
        $sdk = new GithubSdkClient($builder);
        $sdk->authenticate('ghp_fake123', null, AuthMethod::ACCESS_TOKEN);

        return new GitHubClient($integration, $sdk);
    }

    private function jsonResponse(mixed $data, int $status = 200): Response
    {
        $json = json_encode($data);

        return new Response($status, ['Content-Type' => 'application/json'], is_string($json) ? $json : '{}');
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeUser(): array
    {
        return [
            'id' => 1,
            'login' => 'testuser',
            'node_id' => 'MDQ6VXNlcjE=',
            'avatar_url' => 'https://example.com/avatar.png',
            'url' => 'https://api.github.com/users/testuser',
            'html_url' => 'https://github.com/testuser',
            'type' => 'User',
            'site_admin' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeIssue(string $state = 'open'): array
    {
        return [
            'id' => 1,
            'number' => 42,
            'node_id' => 'MDU6SXNzdWUx',
            'title' => 'Test issue',
            'body' => 'Body text',
            'body_html' => '<p>Body text</p>',
            'state' => $state,
            'url' => 'https://api.github.com/repos/acme/widgets/issues/42',
            'html_url' => 'https://github.com/acme/widgets/issues/42',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'user' => $this->fakeUser(),
        ];
    }

    public function test_create_issue_returns_issue_data(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse($this->fakeIssue(), 201));

        $client = $this->createClient($mockHttp);
        $result = $client->createIssue('Test issue', 'Body text');

        $this->assertInstanceOf(GitHubIssueData::class, $result);
        $this->assertSame(42, $result->number);
        $this->assertSame('Test issue', $result->title);
    }

    public function test_get_issue_returns_array(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse([
            'id' => 1,
            'number' => 42,
            'title' => 'Test issue',
            'state' => 'open',
        ]));

        $client = $this->createClient($mockHttp);
        $result = $client->getIssue(42);

        $this->assertIsArray($result);
        $this->assertSame(42, $result['number']);
    }

    public function test_get_issues_since_calls_callback_per_issue(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse([
            ['id' => 1, 'number' => 1, 'title' => 'Issue 1', 'state' => 'open'],
            ['id' => 2, 'number' => 2, 'title' => 'Issue 2', 'state' => 'open'],
        ]));
        // Empty second page to stop pagination.
        $mockHttp->addResponse($this->jsonResponse([]));

        $client = $this->createClient($mockHttp);
        $issues = [];
        $client->getIssuesSince(new \DateTimeImmutable('2026-01-01'), function (array $issue) use (&$issues): void {
            $issues[] = $issue;
        });

        $this->assertCount(2, $issues);
    }

    public function test_get_issues_since_skips_pull_requests(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse([
            ['id' => 1, 'number' => 1, 'title' => 'Issue', 'state' => 'open'],
            ['id' => 2, 'number' => 2, 'title' => 'PR', 'state' => 'open', 'pull_request' => ['url' => 'https://...']],
        ]));
        $mockHttp->addResponse($this->jsonResponse([]));

        $client = $this->createClient($mockHttp);
        $issues = [];
        $client->getIssuesSince(new \DateTimeImmutable('2026-01-01'), function (array $issue) use (&$issues): void {
            $issues[] = $issue;
        });

        $this->assertCount(1, $issues);
        $this->assertSame('Issue', $issues[0]['title']);
    }

    public function test_get_issue_comments_calls_callback_per_comment(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse([
            ['id' => 100, 'body' => 'Comment 1', 'body_html' => '<p>Comment 1</p>', 'user' => ['id' => 1, 'login' => 'user1'], 'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z'],
            ['id' => 101, 'body' => 'Comment 2', 'body_html' => '<p>Comment 2</p>', 'user' => ['id' => 2, 'login' => 'user2'], 'created_at' => '2026-01-02T00:00:00Z', 'updated_at' => '2026-01-02T00:00:00Z'],
        ]));
        $mockHttp->addResponse($this->jsonResponse([]));

        $client = $this->createClient($mockHttp);
        $comments = [];
        $client->getIssueComments(42, function (array $comment) use (&$comments): void {
            $comments[] = $comment;
        });

        $this->assertCount(2, $comments);
    }

    public function test_close_issue_returns_issue_data(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse($this->fakeIssue('closed')));

        $client = $this->createClient($mockHttp);
        $result = $client->closeIssue(42);

        $this->assertInstanceOf(GitHubIssueData::class, $result);
        $this->assertSame('closed', $result->state);
    }

    public function test_add_comment_returns_comment_data(): void
    {
        config(['app.debug' => true]);

        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse([
            'id' => 200,
            'node_id' => 'MDEyOklzc3VlQ29tbWVudDIwMA==',
            'body' => 'New comment',
            'body_html' => '<p>New comment</p>',
            'url' => 'https://api.github.com/repos/acme/widgets/issues/comments/200',
            'html_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-200',
            'issue_url' => 'https://api.github.com/repos/acme/widgets/issues/42',
            'user' => $this->fakeUser(),
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ]));

        $client = $this->createClient($mockHttp);
        $result = $client->addComment(42, 'New comment');

        $this->assertInstanceOf(GitHubCommentData::class, $result);
        $this->assertSame('New comment', $result->body);
    }
}
