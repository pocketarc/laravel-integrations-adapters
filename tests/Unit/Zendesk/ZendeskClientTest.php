<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Zendesk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Integrations\Adapters\Tests\TestCase;
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

    private function jsonResponse(mixed $data, int $status = 200): Response
    {
        $json = json_encode($data);

        return new Response($status, ['Content-Type' => 'application/json'], is_string($json) ? $json : '{}');
    }

    public function test_get_ticket_returns_stdclass(): void
    {
        $mockHandler = new MockHandler([
            $this->jsonResponse(['ticket' => ['id' => 123, 'subject' => 'Test ticket']]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        $result = $client->tickets()->get(123);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame(123, $result->id);
    }

    public function test_get_user_returns_stdclass(): void
    {
        $mockHandler = new MockHandler([
            $this->jsonResponse(['user' => ['id' => 456, 'name' => 'Test User', 'email' => 'user@test.com']]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        $result = $client->users()->get(456);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertSame(456, $result->id);
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
