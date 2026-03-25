<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Zendesk;

use Illuminate\Support\Facades\Http;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Adapters\Zendesk\ZendeskCredentials;
use Integrations\Adapters\Zendesk\ZendeskMetadata;
use Integrations\Adapters\Zendesk\ZendeskProvider;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\IntegrationProvider;

class ZendeskProviderTest extends TestCase
{
    public function test_implements_required_contracts(): void
    {
        $provider = new ZendeskProvider;

        $this->assertInstanceOf(IntegrationProvider::class, $provider);
        $this->assertInstanceOf(HasHealthCheck::class, $provider);
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

    public function test_health_check_returns_true_on_success(): void
    {
        Http::fake([
            'acme.zendesk.com/api/v2/users/me.json' => Http::response(['user' => ['id' => 1]], 200),
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
        Http::fake([
            'acme.zendesk.com/api/v2/users/me.json' => Http::response('Unauthorized', 401),
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
}
