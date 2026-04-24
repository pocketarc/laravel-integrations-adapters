<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Postmark;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Integrations\Adapters\Postmark\Data\PostmarkBounceData;
use Integrations\Adapters\Postmark\Data\PostmarkBounceListResponse;
use Integrations\Adapters\Postmark\Enums\PostmarkBounceType;
use Integrations\Adapters\Postmark\PostmarkClient;
use Integrations\Adapters\Postmark\PostmarkProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Models\Integration;
use Integrations\Testing\CreatesIntegration;
use Postmark\PostmarkClient as PostmarkSdkClient;

/**
 * The wildbit SDK uses Guzzle directly (not Laravel's HTTP facade), so we
 * inject a custom Guzzle client via the SDK's `setClient()` and queue
 * canned responses with a MockHandler — the same pattern the Zendesk
 * tests use.
 */
class PostmarkClientTest extends TestCase
{
    use CreatesIntegration;

    public function test_get_bounce_returns_typed_bounce_data(): void
    {
        $client = $this->createClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($this->fakeBounce())),
        ]));

        $bounce = $client->bounces()->get(12345);

        $this->assertInstanceOf(PostmarkBounceData::class, $bounce);
        $this->assertSame(12345, $bounce->ID);
        $this->assertSame(PostmarkBounceType::HardBounce, $bounce->Type);
        $this->assertSame('gone@example.com', $bounce->Email);
        $this->assertTrue($bounce->Inactive);
        $this->assertFalse($bounce->CanActivate);
        $this->assertIsArray($bounce->original);
    }

    public function test_list_bounces_returns_typed_list_response(): void
    {
        $client = $this->createClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'TotalCount' => 2,
                'Bounces' => [$this->fakeBounce(), $this->fakeBounce(['ID' => 99999, 'Email' => 'other@example.com'])],
            ])),
        ]));

        $response = $client->bounces()->list(count: 50, offset: 0);

        $this->assertInstanceOf(PostmarkBounceListResponse::class, $response);
        $this->assertSame(2, $response->TotalCount);
        $this->assertCount(2, $response->Bounces);
        $this->assertInstanceOf(PostmarkBounceData::class, $response->Bounces->first());
    }

    public function test_deferred_credential_validation_throws_on_first_use(): void
    {
        // Construct a client against an integration with no typed
        // credentials — the client itself constructs fine, but its
        // `boot()` on first SDK access explodes with a clear message.
        // Calling getSdkClient() directly dodges the `request()` pipeline,
        // which otherwise wraps us in provider-resolution or retry logic.
        $integration = Integration::create(['provider' => 'postmark', 'name' => 'Postmark']);

        $client = new PostmarkClient($integration);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('credentials must be');

        $client->getSdkClient();
    }

    public function test_client_exposes_all_resource_accessors(): void
    {
        $client = $this->createClient(new MockHandler([]));

        $this->assertNotNull($client->bounces());
        $this->assertNotNull($client->suppressions());
        $this->assertNotNull($client->messages());
        $this->assertNotNull($client->serverStats());
        $this->assertNotNull($client->webhookEndpoints());
    }

    public function test_default_message_stream_reads_from_metadata(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-abc'],
            metadata: ['message_stream' => 'broadcasts'],
        );

        $client = new PostmarkClient($integration, $this->createMockSdk(new MockHandler([])));

        $this->assertSame('broadcasts', $client->defaultMessageStream());
    }

    public function test_default_message_stream_falls_back_to_outbound_without_metadata(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-abc'],
        );

        $client = new PostmarkClient($integration, $this->createMockSdk(new MockHandler([])));

        $this->assertSame('outbound', $client->defaultMessageStream());
    }

    private function createClient(MockHandler $mockHandler): PostmarkClient
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-abc'],
            metadata: ['message_stream' => 'outbound'],
        );

        return new PostmarkClient($integration, $this->createMockSdk($mockHandler));
    }

    private function createMockSdk(MockHandler $mockHandler): PostmarkSdkClient
    {
        $sdk = new PostmarkSdkClient('srv-abc');
        $sdk->setClient(new GuzzleClient(['handler' => HandlerStack::create($mockHandler)]));

        return $sdk;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fakeBounce(array $overrides = []): array
    {
        return array_merge([
            'RecordType' => 'Bounce',
            'ID' => 12345,
            'Type' => 'HardBounce',
            'TypeCode' => 1,
            'Name' => 'Hard bounce',
            'Tag' => 'welcome',
            'MessageID' => 'msg-1',
            'ServerID' => 1,
            'MessageStream' => 'outbound',
            'Description' => 'The server was unable to deliver your message',
            'Details' => 'smtp;550 5.1.1 user unknown',
            'Email' => 'gone@example.com',
            'From' => 'app@example.com',
            'BouncedAt' => '2026-04-23T10:00:00Z',
            'DumpAvailable' => true,
            'Inactive' => true,
            'CanActivate' => false,
            'Subject' => 'Welcome',
            'Content' => '',
        ], $overrides);
    }
}
