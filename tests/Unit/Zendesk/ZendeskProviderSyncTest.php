<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Zendesk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Adapters\Zendesk\Events\ZendeskTicketSynced;
use Integrations\Adapters\Zendesk\ZendeskClient;
use Integrations\Adapters\Zendesk\ZendeskProvider;
use Integrations\Models\Integration;
use Integrations\Sync\SyncItemEvent;
use Integrations\Testing\CreatesIntegration;
use Integrations\Testing\FakeSyncSession;
use Zendesk\API\HttpClient as ZendeskAPI;

class ZendeskProviderSyncTest extends TestCase
{
    use CreatesIntegration;

    public function test_sync_incremental_dispatches_each_ticket_to_the_session(): void
    {
        $integration = $this->createIntegrationModel();
        $provider = $this->makeProviderWithMockedSdk(new MockHandler([
            $this->jsonResponse([
                'tickets' => [
                    array_merge($this->fakeTicket(), ['id' => 1001, 'updated_at' => '2026-01-01T10:00:00Z']),
                    array_merge($this->fakeTicket(), ['id' => 1002, 'updated_at' => '2026-01-01T12:00:00Z']),
                ],
                'users' => [],
                'next_page' => null,
                'end_of_stream' => true,
                'count' => 2,
            ]),
        ]));

        $session = new FakeSyncSession($integration);
        $provider->syncIncremental($integration, $session);

        $session->assertDispatchedCount(2);
        $session->assertDispatched(
            ZendeskTicketSynced::class,
            fn (SyncItemEvent $event, mixed $checkpoint, ?string $externalId): bool => $event instanceof ZendeskTicketSynced
                && $event->ticket->id === 1001
                && $checkpoint === '2026-01-01T10:00:00+00:00'
                && $externalId === '1001',
        );
        $session->assertDispatched(
            ZendeskTicketSynced::class,
            fn (SyncItemEvent $event, mixed $checkpoint, ?string $externalId): bool => $event instanceof ZendeskTicketSynced
                && $event->ticket->id === 1002
                && $checkpoint === '2026-01-01T12:00:00+00:00'
                && $externalId === '1002',
        );
    }

    public function test_sync_incremental_scopes_the_fetch_by_the_session_cursor(): void
    {
        // A seeded cursor means syncIncremental fetches from cursor - 1h.
        $integration = $this->createIntegrationModel();
        $integration->updateSyncCursor('2026-06-01T12:00:00+00:00');

        $mockHandler = new MockHandler([
            $this->jsonResponse([
                'tickets' => [],
                'users' => [],
                'next_page' => null,
                'end_of_stream' => true,
                'count' => 0,
            ]),
        ]);
        $provider = $this->makeProviderWithMockedSdk($mockHandler);

        $session = new FakeSyncSession($integration);
        $provider->syncIncremental($integration, $session);

        $session->assertNothingDispatched();

        $expectedStart = Carbon::parse('2026-06-01T11:00:00+00:00')->getTimestamp();
        $lastRequest = $mockHandler->getLastRequest();
        $this->assertNotNull($lastRequest);
        $this->assertStringContainsString("start_time={$expectedStart}", $lastRequest->getUri()->getQuery());
    }

    public function test_reduce_checkpoints_returns_the_latest_checkpoint(): void
    {
        $provider = new ZendeskProvider;

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
            providerKey: 'zendesk',
            providerClass: ZendeskProvider::class,
            credentials: ['email' => 'test@acme.com', 'token' => 'fake-token'],
            metadata: ['subdomain' => 'acme'],
        );
    }

    private function makeProviderWithMockedSdk(MockHandler $mockHandler): ZendeskProvider
    {
        $sdk = new ZendeskAPI('acme');
        $sdk->setAuth('basic', ['username' => 'test@acme.com', 'token' => 'fake-token']);
        $sdk->guzzle = new GuzzleClient(['handler' => HandlerStack::create($mockHandler)]);

        return new class($sdk) extends ZendeskProvider
        {
            public function __construct(private readonly ZendeskAPI $injectedSdk) {}

            #[\Override]
            protected function makeClient(Integration $integration): ZendeskClient
            {
                return new ZendeskClient($integration, $this->injectedSdk);
            }
        };
    }

    private function jsonResponse(mixed $data, int $status = 200): Response
    {
        $json = json_encode($data);

        return new Response($status, ['Content-Type' => 'application/json'], is_string($json) ? $json : '{}');
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeTicket(): array
    {
        return [
            'id' => 123,
            'url' => 'https://acme.zendesk.com/api/v2/tickets/123.json',
            'external_id' => null,
            'subject' => 'Test ticket',
            'raw_subject' => 'Test ticket',
            'description' => 'Test ticket description',
            'status' => 'open',
            'type' => null,
            'priority' => null,
            'custom_status_id' => 0,
            'requester_id' => 1,
            'submitter_id' => 1,
            'assignee_id' => null,
            'organization_id' => null,
            'group_id' => 1,
            'brand_id' => null,
            'forum_topic_id' => null,
            'problem_id' => null,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'due_at' => null,
            'generated_timestamp' => 1735689600,
            'via' => ['channel' => 'web', 'source' => []],
            'custom_fields' => [],
            'has_incidents' => false,
            'is_public' => true,
            'allow_channelback' => false,
            'allow_attachments' => true,
            'from_messaging_channel' => false,
        ];
    }
}
