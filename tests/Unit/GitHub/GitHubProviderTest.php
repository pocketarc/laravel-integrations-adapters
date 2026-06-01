<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\GitHub;

use Github\AuthMethod;
use Github\Client as GithubSdkClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockHttpClient;
use Illuminate\Support\Facades\Http;
use Integrations\Adapters\GitHub\GitHubClient;
use Integrations\Adapters\GitHub\GitHubCredentials;
use Integrations\Adapters\GitHub\GitHubMetadata;
use Integrations\Adapters\GitHub\GitHubProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\HasIncrementalSync;
use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\RedactsRequestData;
use Integrations\Enums\RateLimitWindow;
use Integrations\Models\Integration;

class GitHubProviderTest extends TestCase
{
    public function test_implements_required_contracts(): void
    {
        $provider = new GitHubProvider;

        $this->assertInstanceOf(IntegrationProvider::class, $provider);
        $this->assertInstanceOf(HasHealthCheck::class, $provider);
        $this->assertInstanceOf(HasIncrementalSync::class, $provider);
        $this->assertInstanceOf(IdentifiesAuthenticatedUser::class, $provider);
        $this->assertInstanceOf(RedactsRequestData::class, $provider);
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

    public function test_default_sync_interval(): void
    {
        $provider = new GitHubProvider;

        $this->assertSame(5, $provider->defaultSyncInterval());
    }

    public function test_default_rate_limit(): void
    {
        $limit = (new GitHubProvider)->defaultRateLimit();

        $this->assertSame(5000, $limit->limit);
        $this->assertSame(3600, $limit->windowSeconds);
        $this->assertSame(RateLimitWindow::Fixed, $limit->window);
    }

    public function test_sensitive_fields_return_arrays(): void
    {
        $provider = new GitHubProvider;

        $this->assertIsArray($provider->sensitiveRequestFields());
        $this->assertIsArray($provider->sensitiveResponseFields());
    }

    public function test_health_check_returns_true_on_success(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/repos/pocketarc/laravel-integrations' => Http::response(['id' => 1], 200),
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
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/repos/pocketarc/laravel-integrations' => Http::response('Not Found', 404),
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

    public function test_authenticated_user_maps_the_github_user(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'github',
            providerClass: GitHubProvider::class,
            credentials: ['token' => 'ghp_fake123'],
            metadata: ['owner' => 'acme', 'repo' => 'widgets'],
        );

        $json = json_encode([
            'id' => 583231,
            'login' => 'octocat',
            'node_id' => 'MDQ6VXNlcjE=',
            'avatar_url' => 'https://example.com/a.png',
            'url' => 'https://api.github.com/users/octocat',
            'html_url' => 'https://github.com/octocat',
            'type' => 'User',
            'site_admin' => false,
            'name' => 'The Octocat',
            'email' => 'octo@example.com',
        ]);

        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse(new Response(200, ['Content-Type' => 'application/json'], is_string($json) ? $json : '{}'));

        $sdk = new GithubSdkClient(new Builder($mockHttp));
        $sdk->authenticate('ghp_fake123', null, AuthMethod::ACCESS_TOKEN);

        $provider = new class($sdk) extends GitHubProvider
        {
            public function __construct(private readonly GithubSdkClient $injectedSdk) {}

            #[\Override]
            protected function makeClient(Integration $integration): GitHubClient
            {
                return new GitHubClient($integration, $this->injectedSdk);
            }
        };

        $user = $provider->authenticatedUser($integration);

        $this->assertSame('583231', $user->id);
        $this->assertSame('octocat', $user->username);
        $this->assertSame('The Octocat', $user->name);
        $this->assertSame('octo@example.com', $user->email);
        $this->assertSame('octocat', $user->raw['login']);
    }
}
