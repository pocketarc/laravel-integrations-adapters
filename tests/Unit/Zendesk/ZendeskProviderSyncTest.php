<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Zendesk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Adapters\Zendesk\Events\ZendeskTicketSynced;
use Integrations\Adapters\Zendesk\ZendeskClient;
use Integrations\Adapters\Zendesk\ZendeskProvider;
use Integrations\Models\Integration;
use Integrations\Testing\CreatesIntegration;
use RuntimeException;
use Zendesk\API\HttpClient as ZendeskAPI;

class ZendeskProviderSyncTest extends TestCase
{
    use CreatesIntegration;

    public function test_sync_incremental_checkpoints_cursor_per_successful_ticket(): void
    {
        $integration = $this->createIntegrationModel();
        $provider = $this->makeProviderWithMockedSdk(new MockHandler([
            $this->jsonResponse([
                'tickets' => [
                    array_merge($this->fakeTicket(), ['id' => 1001, 'updated_at' => '2026-01-01T10:00:00Z']),
                    array_merge($this->fakeTicket(), ['id' => 1002, 'updated_at' => '2026-01-01T11:00:00Z']),
                    array_merge($this->fakeTicket(), ['id' => 1003, 'updated_at' => '2026-01-01T12:00:00Z']),
                ],
                'users' => [],
                'next_page' => null,
                'end_of_stream' => true,
                'count' => 3,
            ]),
        ]));

        $cursorsWritten = [];
        Integration::updated(function (Integration $i) use ($integration, &$cursorsWritten): void {
            if ($i->id === $integration->id && is_string($i->sync_cursor)) {
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
        $provider = $this->makeProviderWithMockedSdk(new MockHandler([
            $this->jsonResponse([
                'tickets' => [
                    array_merge($this->fakeTicket(), ['id' => 1001, 'updated_at' => '2026-01-01T10:00:00Z']),
                    array_merge($this->fakeTicket(), ['id' => 1002, 'updated_at' => '2026-01-01T11:00:00Z']),
                    array_merge($this->fakeTicket(), ['id' => 1003, 'updated_at' => '2026-01-01T12:00:00Z']),
                ],
                'users' => [],
                'next_page' => null,
                'end_of_stream' => true,
                'count' => 3,
            ]),
        ]));

        $count = 0;
        Event::listen(ZendeskTicketSynced::class, function () use (&$count): void {
            $count++;
            if ($count === 2) {
                throw new RuntimeException('simulated listener failure');
            }
        });

        $cursorsWritten = [];
        Integration::updated(function (Integration $i) use ($integration, &$cursorsWritten): void {
            if ($i->id === $integration->id && is_string($i->sync_cursor)) {
                $cursorsWritten[] = $i->sync_cursor;
            }
        });

        $provider->syncIncremental($integration, null);

        // The first ticket succeeded so its cursor was checkpointed.
        // The second threw, so no checkpoint. The third succeeded but came
        // after the failure, so it must NOT advance the cursor past the
        // known-bad ticket.
        $this->assertSame(['2026-01-01T10:00:00+00:00'], $cursorsWritten);
    }

    public function test_sync_incremental_skips_checkpoint_when_updated_at_does_not_advance(): void
    {
        // Two tickets with identical updated_at; the second should not trigger
        // a redundant write. Defends against re-writing the same cursor value.
        $integration = $this->createIntegrationModel();
        $provider = $this->makeProviderWithMockedSdk(new MockHandler([
            $this->jsonResponse([
                'tickets' => [
                    array_merge($this->fakeTicket(), ['id' => 1001, 'updated_at' => '2026-01-01T10:00:00Z']),
                    array_merge($this->fakeTicket(), ['id' => 1002, 'updated_at' => '2026-01-01T10:00:00Z']),
                ],
                'users' => [],
                'next_page' => null,
                'end_of_stream' => true,
                'count' => 2,
            ]),
        ]));

        $cursorsWritten = [];
        Integration::updated(function (Integration $i) use ($integration, &$cursorsWritten): void {
            if ($i->id === $integration->id && is_string($i->sync_cursor)) {
                $cursorsWritten[] = $i->sync_cursor;
            }
        });

        $provider->syncIncremental($integration, null);

        $this->assertSame(['2026-01-01T10:00:00+00:00'], $cursorsWritten);
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
