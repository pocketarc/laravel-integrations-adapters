<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Zendesk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Adapters\Zendesk\ZendeskClient;
use Integrations\Adapters\Zendesk\ZendeskCredentials;
use Integrations\Adapters\Zendesk\ZendeskMetadata;
use Integrations\Adapters\Zendesk\ZendeskProvider;
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\HasIncrementalSync;
use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\RedactsRequestData;
use Integrations\Enums\FailureClass;
use Integrations\Enums\RateLimitWindow;
use Integrations\Models\Integration;
use RuntimeException;
use Zendesk\API\HttpClient as ZendeskAPI;

class ZendeskProviderTest extends TestCase
{
    public function test_implements_required_contracts(): void
    {
        $provider = new ZendeskProvider;

        $this->assertInstanceOf(IntegrationProvider::class, $provider);
        $this->assertInstanceOf(HasHealthCheck::class, $provider);
        $this->assertInstanceOf(HasIncrementalSync::class, $provider);
        $this->assertInstanceOf(RedactsRequestData::class, $provider);
        $this->assertInstanceOf(ClassifiesFailures::class, $provider);
        $this->assertInstanceOf(IdentifiesAuthenticatedUser::class, $provider);
    }

    public function test_classify_failure(): void
    {
        $provider = new ZendeskProvider;

        // The Zendesk SDK wraps the Guzzle RequestException (which core reads
        // off the chain), so the provider only maps a bare connection error,
        // which has no status, to an upstream fault.
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(new ConnectException('refused', new Request('GET', 'https://x.zendesk.com'))));

        // Everything else defers to the core classifier.
        $this->assertNull($provider->classifyFailure(new RuntimeException('mystery')));
    }

    public function test_name(): void
    {
        $provider = new ZendeskProvider;

        $this->assertSame('Zendesk', $provider->name());
    }

    public function test_credential_rules(): void
    {
        $provider = new ZendeskProvider;
        $rules = $provider->credentialRules();

        $this->assertArrayHasKey('email', $rules);
        $this->assertArrayHasKey('token', $rules);
    }

    public function test_metadata_rules(): void
    {
        $provider = new ZendeskProvider;
        $rules = $provider->metadataRules();

        $this->assertArrayHasKey('subdomain', $rules);
        $this->assertArrayHasKey('custom_domain', $rules);
    }

    public function test_data_classes(): void
    {
        $provider = new ZendeskProvider;

        $this->assertSame(ZendeskCredentials::class, $provider->credentialDataClass());
        $this->assertSame(ZendeskMetadata::class, $provider->metadataDataClass());
    }

    public function test_creates_integration_with_typed_credentials(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'zendesk',
            providerClass: ZendeskProvider::class,
            credentials: ['email' => 'admin@acme.com', 'token' => 'abc123'],
            metadata: ['subdomain' => 'acme'],
        );

        $this->assertTrue($integration->is_active);
        $this->assertInstanceOf(ZendeskCredentials::class, $integration->credentials);
        $this->assertSame('admin@acme.com', $integration->credentials->email);
        $this->assertSame('abc123', $integration->credentials->token);
        $this->assertInstanceOf(ZendeskMetadata::class, $integration->metadata);
        $this->assertSame('acme', $integration->metadata->subdomain);
        $this->assertNull($integration->metadata->custom_domain);
    }

    public function test_default_sync_interval(): void
    {
        $provider = new ZendeskProvider;

        $this->assertSame(5, $provider->defaultSyncInterval());
    }

    public function test_default_rate_limit(): void
    {
        $limit = (new ZendeskProvider)->defaultRateLimit();

        $this->assertSame(100, $limit->limit);
        $this->assertSame(60, $limit->windowSeconds);
        $this->assertSame(RateLimitWindow::Sliding, $limit->window);
    }

    public function test_sensitive_fields_return_arrays(): void
    {
        $provider = new ZendeskProvider;

        $this->assertIsArray($provider->sensitiveRequestFields());
        $this->assertIsArray($provider->sensitiveResponseFields());
    }

    public function test_health_check_returns_true_on_success(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.zendesk.com/api/v2/users/me.json' => Http::response(['user' => ['id' => 1]], 200),
        ]);

        $provider = new ZendeskProvider;
        $integration = $this->createIntegration(
            providerKey: 'zendesk',
            providerClass: ZendeskProvider::class,
            credentials: ['email' => 'admin@acme.com', 'token' => 'abc123'],
            metadata: ['subdomain' => 'acme'],
        );

        $this->assertTrue($provider->healthCheck($integration));
    }

    public function test_health_check_returns_false_on_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://acme.zendesk.com/api/v2/users/me.json' => Http::response('Unauthorized', 401),
        ]);

        $provider = new ZendeskProvider;
        $integration = $this->createIntegration(
            providerKey: 'zendesk',
            providerClass: ZendeskProvider::class,
            credentials: ['email' => 'admin@acme.com', 'token' => 'bad-token'],
            metadata: ['subdomain' => 'acme'],
        );

        $this->assertFalse($provider->healthCheck($integration));
    }

    public function test_health_check_uses_custom_domain_when_set(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://support.acme.com/api/v2/users/me.json' => Http::response(['user' => ['id' => 1]], 200),
        ]);

        $provider = new ZendeskProvider;
        $integration = $this->createIntegration(
            providerKey: 'zendesk',
            providerClass: ZendeskProvider::class,
            credentials: ['email' => 'admin@acme.com', 'token' => 'abc123'],
            metadata: ['subdomain' => 'acme', 'custom_domain' => 'https://support.acme.com'],
        );

        $this->assertTrue($provider->healthCheck($integration));
    }

    public function test_authenticated_user_maps_the_current_user(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'zendesk',
            providerClass: ZendeskProvider::class,
            credentials: ['email' => 'admin@acme.com', 'token' => 'abc123'],
            metadata: ['subdomain' => 'acme'],
        );

        $provider = $this->makeProviderWithMockedSdk(new MockHandler([
            $this->jsonResponse(['user' => $this->fakeUser()]),
        ]));

        $user = $provider->authenticatedUser($integration);

        $this->assertSame('456', $user->id);
        $this->assertSame('agent@acme.com', $user->username);
        $this->assertSame('Acme Agent', $user->name);
        $this->assertSame('agent@acme.com', $user->email);
        $this->assertSame(456, $user->raw['id']);
    }

    private function makeProviderWithMockedSdk(MockHandler $mockHandler): ZendeskProvider
    {
        $sdk = new ZendeskAPI('acme');
        $sdk->setAuth('basic', ['username' => 'admin@acme.com', 'token' => 'abc123']);
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
    private function fakeUser(): array
    {
        return [
            'id' => 456,
            'url' => 'https://acme.zendesk.com/api/v2/users/456.json',
            'name' => 'Acme Agent',
            'email' => 'agent@acme.com',
            'external_id' => null,
            'active' => true,
            'suspended' => false,
            'verified' => true,
            'role' => 'agent',
            'role_type' => null,
            'custom_role_id' => null,
            'moderator' => false,
            'ticket_restriction' => null,
            'only_private_comments' => false,
            'restricted_agent' => false,
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
}
