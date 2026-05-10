<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\GitHub;

use Github\AuthMethod;
use Github\Client as GithubSdkClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockHttpClient;
use Illuminate\Support\Facades\Event;
use Integrations\Adapters\GitHub\Events\GitHubIssueSynced;
use Integrations\Adapters\GitHub\GitHubClient;
use Integrations\Adapters\GitHub\GitHubProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Models\Integration;
use Integrations\Testing\CreatesIntegration;
use RuntimeException;

class GitHubProviderSyncTest extends TestCase
{
    use CreatesIntegration;

    public function test_sync_incremental_checkpoints_cursor_per_successful_issue(): void
    {
        $integration = $this->createIntegrationModel();
        $provider = $this->makeProviderWithMockedSdk(new MockHttpClient, [
            [
                $this->fakeIssue(['id' => 1, 'number' => 1, 'updated_at' => '2026-01-01T10:00:00Z']),
                $this->fakeIssue(['id' => 2, 'number' => 2, 'updated_at' => '2026-01-01T11:00:00Z']),
                $this->fakeIssue(['id' => 3, 'number' => 3, 'updated_at' => '2026-01-01T12:00:00Z']),
            ],
        ]);

        $cursorsWritten = [];
        Integration::updated(function (Integration $i) use ($integration, &$cursorsWritten): void {
            if ($i->id === $integration->id && $i->wasChanged('sync_cursor') && is_string($i->sync_cursor)) {
                $cursorsWritten[] = $i->sync_cursor;
            }
        });

        $provider->syncIncremental($integration, null);

        $this->assertSame([
            '2026-01-01T10:00:00+00:00',
            '2026-01-01T11:00:00+00:00',
            '2026-01-01T12:00:00+00:00',
        ], $cursorsWritten);
    }

    public function test_sync_incremental_stops_checkpointing_after_a_failure(): void
    {
        $integration = $this->createIntegrationModel();
        $provider = $this->makeProviderWithMockedSdk(new MockHttpClient, [
            [
                $this->fakeIssue(['id' => 1, 'number' => 1, 'updated_at' => '2026-01-01T10:00:00Z']),
                $this->fakeIssue(['id' => 2, 'number' => 2, 'updated_at' => '2026-01-01T11:00:00Z']),
                $this->fakeIssue(['id' => 3, 'number' => 3, 'updated_at' => '2026-01-01T12:00:00Z']),
            ],
        ]);

        $count = 0;
        Event::listen(GitHubIssueSynced::class, function () use (&$count): void {
            $count++;
            if ($count === 2) {
                throw new RuntimeException('simulated listener failure');
            }
        });

        $cursorsWritten = [];
        Integration::updated(function (Integration $i) use ($integration, &$cursorsWritten): void {
            if ($i->id === $integration->id && $i->wasChanged('sync_cursor') && is_string($i->sync_cursor)) {
                $cursorsWritten[] = $i->sync_cursor;
            }
        });

        $provider->syncIncremental($integration, null);

        $this->assertSame(['2026-01-01T10:00:00+00:00'], $cursorsWritten);
    }

    public function test_sync_incremental_does_not_regress_cursor_below_the_seeded_value(): void
    {
        $integration = $this->createIntegrationModel();
        $integration->updateSyncCursor('2026-01-01T12:00:00+00:00');

        $provider = $this->makeProviderWithMockedSdk(new MockHttpClient, [
            [
                $this->fakeIssue(['id' => 1, 'number' => 1, 'updated_at' => '2026-01-01T11:30:00Z']),
                $this->fakeIssue(['id' => 2, 'number' => 2, 'updated_at' => '2026-01-01T12:30:00Z']),
            ],
        ]);

        $cursorsWritten = [];
        Integration::updated(function (Integration $i) use ($integration, &$cursorsWritten): void {
            if ($i->id === $integration->id && $i->wasChanged('sync_cursor') && is_string($i->sync_cursor)) {
                $cursorsWritten[] = $i->sync_cursor;
            }
        });

        $provider->syncIncremental($integration, '2026-01-01T12:00:00+00:00');

        $this->assertSame(['2026-01-01T12:30:00+00:00'], $cursorsWritten);
    }

    public function test_sync_result_cursor_does_not_regress_below_the_seeded_value_on_overlap_failure(): void
    {
        $integration = $this->createIntegrationModel();
        $integration->updateSyncCursor('2026-01-01T12:00:00+00:00');

        $provider = $this->makeProviderWithMockedSdk(new MockHttpClient, [
            [
                $this->fakeIssue(['id' => 1, 'number' => 1, 'updated_at' => '2026-01-01T11:30:00Z']),
            ],
        ]);

        Event::listen(GitHubIssueSynced::class, function (): void {
            throw new RuntimeException('simulated listener failure on overlap-window item');
        });

        $result = $provider->syncIncremental($integration, '2026-01-01T12:00:00+00:00');

        $this->assertSame('2026-01-01T12:00:00+00:00', $result->cursor);
    }

    public function test_sync_incremental_does_not_double_count_when_cursor_write_throws(): void
    {
        $integration = $this->createIntegrationModel();
        $provider = $this->makeProviderWithMockedSdk(new MockHttpClient, [
            [
                $this->fakeIssue(['id' => 1, 'number' => 1, 'updated_at' => '2026-01-01T10:00:00Z']),
                $this->fakeIssue(['id' => 2, 'number' => 2, 'updated_at' => '2026-01-01T11:00:00Z']),
                $this->fakeIssue(['id' => 3, 'number' => 3, 'updated_at' => '2026-01-01T12:00:00Z']),
            ],
        ]);

        Integration::saving(function (Integration $i) use ($integration): void {
            if ($i->id === $integration->id && $i->sync_cursor === '2026-01-01T11:00:00+00:00') {
                throw new RuntimeException('simulated DB write failure');
            }
        });

        $result = $provider->syncIncremental($integration, null);

        $this->assertSame(2, $result->successCount);
        $this->assertSame(1, $result->failureCount);
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

        // GitHubIssues::since() loops while count($issues) === $perPage (100), so
        // a non-full final page terminates the loop without an extra request.
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
