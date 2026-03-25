<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\GitHub;

use Illuminate\Support\Facades\Http;
use Integrations\Adapters\GitHub\GitHubCredentials;
use Integrations\Adapters\GitHub\GitHubMetadata;
use Integrations\Adapters\GitHub\GitHubProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\IntegrationProvider;

class GitHubProviderTest extends TestCase
{
    public function test_implements_required_contracts(): void
    {
        $provider = new GitHubProvider;

        $this->assertInstanceOf(IntegrationProvider::class, $provider);
        $this->assertInstanceOf(HasHealthCheck::class, $provider);
    }

    public function test_name(): void
    {
        $provider = new GitHubProvider;

        $this->assertSame('GitHub', $provider->name());
    }

    public function test_credential_rules(): void
    {
        $provider = new GitHubProvider;
        $rules = $provider->credentialRules();

        $this->assertArrayHasKey('token', $rules);
    }

    public function test_metadata_rules(): void
    {
        $provider = new GitHubProvider;
        $rules = $provider->metadataRules();

        $this->assertArrayHasKey('owner', $rules);
        $this->assertArrayHasKey('repo', $rules);
    }

    public function test_data_classes(): void
    {
        $provider = new GitHubProvider;

        $this->assertSame(GitHubCredentials::class, $provider->credentialDataClass());
        $this->assertSame(GitHubMetadata::class, $provider->metadataDataClass());
    }

    public function test_creates_integration_with_typed_credentials(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'github',
            providerClass: GitHubProvider::class,
            credentials: ['token' => 'ghp_abc123'],
            metadata: ['owner' => 'pocketarc', 'repo' => 'laravel-integrations'],
        );

        $this->assertTrue($integration->is_active);
        $this->assertInstanceOf(GitHubCredentials::class, $integration->credentials);
        $this->assertSame('ghp_abc123', $integration->credentials->token);
        $this->assertInstanceOf(GitHubMetadata::class, $integration->metadata);
        $this->assertSame('pocketarc', $integration->metadata->owner);
        $this->assertSame('laravel-integrations', $integration->metadata->repo);
    }

    public function test_health_check_returns_true_on_success(): void
    {
        Http::fake([
            'api.github.com/repos/pocketarc/laravel-integrations' => Http::response(['id' => 1], 200),
        ]);

        $provider = new GitHubProvider;
        $integration = $this->createIntegration(
            providerKey: 'github',
            providerClass: GitHubProvider::class,
            credentials: ['token' => 'ghp_abc123'],
            metadata: ['owner' => 'pocketarc', 'repo' => 'laravel-integrations'],
        );

        $this->assertTrue($provider->healthCheck($integration));
    }

    public function test_health_check_returns_false_on_failure(): void
    {
        Http::fake([
            'api.github.com/repos/pocketarc/laravel-integrations' => Http::response('Not Found', 404),
        ]);

        $provider = new GitHubProvider;
        $integration = $this->createIntegration(
            providerKey: 'github',
            providerClass: GitHubProvider::class,
            credentials: ['token' => 'ghp_bad'],
            metadata: ['owner' => 'pocketarc', 'repo' => 'laravel-integrations'],
        );

        $this->assertFalse($provider->healthCheck($integration));
    }
}
