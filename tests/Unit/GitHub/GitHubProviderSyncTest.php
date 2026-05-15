<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\GitHub;

use Github\AuthMethod;
use Github\Client as GithubSdkClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockHttpClient;
use Integrations\Adapters\GitHub\Events\GitHubIssueSynced;
use Integrations\Adapters\GitHub\GitHubClient;
use Integrations\Adapters\GitHub\GitHubProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Models\Integration;
use Integrations\Sync\SyncItemEvent;
use Integrations\Testing\CreatesIntegration;
use Integrations\Testing\FakeSyncSession;

class GitHubProviderSyncTest extends TestCase
{
    use CreatesIntegration;

    public function test_sync_incremental_dispatches_each_issue_to_the_session(): void
    {
        $integration = $this->createIntegrationModel();
        $provider = $this->makeProviderWithMockedSdk(new MockHttpClient, [
            [
                $this->fakeIssue(['id' => 1, 'number' => 1, 'updated_at' => '2026-01-01T10:00:00Z']),
                $this->fakeIssue(['id' => 2, 'number' => 2, 'updated_at' => '2026-01-01T12:00:00Z']),
            ],
        ]);

        $session = new FakeSyncSession($integration);
        $provider->syncIncremental($integration, $session);

        $session->assertDispatchedCount(2);
        $session->assertDispatched(
            GitHubIssueSynced::class,
            fn (SyncItemEvent $event, mixed $checkpoint, ?string $externalId): bool => $event instanceof GitHubIssueSynced
                && $event->issue->number === 1
                && $checkpoint === '2026-01-01T10:00:00+00:00'
                && $externalId === '1',
        );
        $session->assertDispatched(
            GitHubIssueSynced::class,
            fn (SyncItemEvent $event, mixed $checkpoint, ?string $externalId): bool => $event instanceof GitHubIssueSynced
                && $event->issue->number === 2
                && $checkpoint === '2026-01-01T12:00:00+00:00'
                && $externalId === '2',
        );
    }

    public function test_sync_incremental_scopes_the_fetch_by_the_session_cursor(): void
    {
        $integration = $this->createIntegrationModel();
        $integration->updateSyncCursor('2026-06-01T12:00:00+00:00');

        $mockHttp = new MockHttpClient;
        $provider = $this->makeProviderWithMockedSdk($mockHttp, [[]]);

        $session = new FakeSyncSession($integration);
        $provider->syncIncremental($integration, $session);

        $session->assertNothingDispatched();

        // The GitHub issues request scopes by `since` = cursor - 1h.
        $requests = $mockHttp->getRequests();
        $this->assertNotEmpty($requests);
        $this->assertStringContainsString('2026-06-01T11%3A00%3A00', $requests[0]->getUri()->getQuery());
    }

    public function test_reduce_checkpoints_returns_the_latest_checkpoint(): void
    {
        $provider = new GitHubProvider;

        $this->assertSame(
            '2026-01-01T12:00:00+00:00',
            $provider->reduceCheckpoints([
                '2026-01-01T10:00:00+00:00',
                '2026-01-01T12:00:00+00:00',
                '2026-01-01T11:00:00+00:00',
            ]),
        );
        $this->assertNull($provider->reduceCheckpoints([]));
    }

    private function createIntegrationModel(): Integration
    {
        return $this->createIntegration(
            providerKey: 'github',
            providerClass: GitHubProvider::class,
            credentials: ['token' => 'ghp_fake123'],
            metadata: ['owner' => 'acme', 'repo' => 'widgets'],
        );
    }

    /**
     * @param  list<list<array<string, mixed>>>  $pages  one element per page returned by the SDK
     */
    private function makeProviderWithMockedSdk(MockHttpClient $mockHttp, array $pages): GitHubProvider
    {
        foreach ($pages as $page) {
            $mockHttp->addResponse($this->jsonResponse($page));
        }

        $builder = new Builder($mockHttp);
        $sdk = new GithubSdkClient($builder);
        $sdk->authenticate('ghp_fake123', null, AuthMethod::ACCESS_TOKEN);

        return new class($sdk) extends GitHubProvider
        {
            public function __construct(private readonly GithubSdkClient $injectedSdk) {}

            #[\Override]
            protected function makeClient(Integration $integration): GitHubClient
            {
                return new GitHubClient($integration, $this->injectedSdk);
            }
        };
    }

    private function jsonResponse(mixed $data, int $status = 200): Response
    {
        $json = json_encode($data);

        return new Response($status, ['Content-Type' => 'application/json'], is_string($json) ? $json : '{}');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fakeIssue(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'number' => 42,
            'node_id' => 'MDU6SXNzdWUx',
            'title' => 'Test issue',
            'body' => 'Body text',
            'body_html' => '<p>Body text</p>',
            'state' => 'open',
            'url' => 'https://api.github.com/repos/acme/widgets/issues/42',
            'html_url' => 'https://github.com/acme/widgets/issues/42',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'user' => [
                'id' => 1,
                'login' => 'testuser',
                'node_id' => 'MDQ6VXNlcjE=',
                'avatar_url' => 'https://example.com/avatar.png',
                'url' => 'https://api.github.com/users/testuser',
                'html_url' => 'https://github.com/testuser',
                'type' => 'User',
                'site_admin' => false,
            ],
        ], $overrides);
    }
}
