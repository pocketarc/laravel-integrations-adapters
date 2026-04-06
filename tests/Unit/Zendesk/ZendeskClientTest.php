<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Zendesk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Adapters\Zendesk\Data\ZendeskTicketData;
use Integrations\Adapters\Zendesk\Data\ZendeskUserData;
use Integrations\Adapters\Zendesk\ZendeskClient;
use Integrations\Adapters\Zendesk\ZendeskProvider;
use Integrations\Models\Integration;
use Integrations\Testing\CreatesIntegration;
use Zendesk\API\HttpClient as ZendeskAPI;

class ZendeskClientTest extends TestCase
{
    use CreatesIntegration;

    private function createIntegrationModel(): Integration
    {
        return $this->createIntegration(
            providerKey: 'zendesk',
            providerClass: ZendeskProvider::class,
            credentials: ['email' => 'test@acme.com', 'token' => 'fake-token'],
            metadata: ['subdomain' => 'acme'],
        );
    }

    private function createMockSdk(MockHandler $mockHandler): ZendeskAPI
    {
        $handlerStack = HandlerStack::create($mockHandler);
        $sdk = new ZendeskAPI('acme');
        $sdk->setAuth('basic', ['username' => 'test@acme.com', 'token' => 'fake-token']);
        $sdk->guzzle = new GuzzleClient(['handler' => $handlerStack]);

        return $sdk;
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

    /**
     * @return array<string, mixed>
     */
    private function fakeUser(): array
    {
        return [
            'id' => 456,
            'url' => 'https://acme.zendesk.com/api/v2/users/456.json',
            'name' => 'Test User',
            'email' => 'user@test.com',
            'external_id' => null,
            'active' => true,
            'suspended' => false,
            'verified' => true,
            'role' => 'end-user',
            'role_type' => null,
            'custom_role_id' => null,
            'moderator' => false,
            'ticket_restriction' => 'requested',
            'only_private_comments' => false,
            'restricted_agent' => true,
            'organization_id' => null,
            'default_group_id' => null,
            'phone' => null,
            'shared_phone_number' => null,
            'photo' => null,
            'time_zone' => 'UTC',
            'iana_time_zone' => 'Etc/UTC',
            'locale_id' => 1,
            'locale' => 'en-US',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'last_login_at' => null,
            'two_factor_auth_enabled' => null,
            'shared' => false,
            'shared_agent' => false,
            'report_csv' => false,
        ];
    }

    private function jsonResponse(mixed $data, int $status = 200): Response
    {
        $json = json_encode($data);

        return new Response($status, ['Content-Type' => 'application/json'], is_string($json) ? $json : '{}');
    }

    public function test_get_ticket_returns_ticket_data(): void
    {
        $mockHandler = new MockHandler([
            $this->jsonResponse(['ticket' => $this->fakeTicket()]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        $result = $client->tickets()->get(123);

        $this->assertInstanceOf(ZendeskTicketData::class, $result);
        $this->assertSame(123, $result->id);
        $this->assertSame('Test ticket', $result->subject);
    }

    public function test_get_user_returns_user_data(): void
    {
        $mockHandler = new MockHandler([
            $this->jsonResponse(['user' => $this->fakeUser()]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        $result = $client->users()->get(456);

        $this->assertInstanceOf(ZendeskUserData::class, $result);
        $this->assertSame(456, $result->id);
        $this->assertSame('Test User', $result->name);
    }

    public function test_get_ticket_comments_calls_callback_per_comment(): void
    {
        $mockHandler = new MockHandler([
            $this->jsonResponse([
                'comments' => [
                    [
                        'id' => 500,
                        'type' => 'Comment',
                        'audit_id' => 1001,
                        'body' => 'First comment',
                        'html_body' => '<p>First comment</p>',
                        'plain_body' => 'First comment',
                        'author_id' => 1,
                        'public' => true,
                        'attachments' => [],
                        'via' => ['channel' => 'web', 'source' => []],
                        'created_at' => '2026-01-01T00:00:00Z',
                    ],
                    [
                        'id' => 501,
                        'type' => 'Comment',
                        'audit_id' => 1002,
                        'body' => 'Second comment',
                        'html_body' => '<p>Second comment</p>',
                        'plain_body' => 'Second comment',
                        'author_id' => 2,
                        'public' => false,
                        'attachments' => [],
                        'via' => ['channel' => 'web', 'source' => []],
                        'created_at' => '2026-01-02T00:00:00Z',
                    ],
                ],
                'meta' => ['has_more' => false],
            ]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        $comments = [];
        $client->comments()->list(123, function ($comment) use (&$comments): void {
            $comments[] = $comment;
        });

        $this->assertCount(2, $comments);
    }
}
