<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Postmark;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Integrations\Adapters\IntegrationAdaptersServiceProvider;
use Integrations\Adapters\Postmark\PostmarkProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Models\Integration;
use Integrations\Testing\CreatesIntegration;

/**
 * Covers the two paths by which the Postmark adapter wires credentials
 * into Laravel's mail config:
 *
 * - the lazy service-provider hook (`registerMailerOverride()`), which
 *   fires when the host app actually resolves `mail.manager` and only
 *   applies when there's exactly one active Postmark integration
 * - the runtime escape hatch (`useForMail()`), which always applies the
 *   integration's credentials regardless of how many exist
 */
class PostmarkMailerOverrideTest extends TestCase
{
    use CreatesIntegration;

    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    #[\Override]
    protected function getAdapterProviders($app): array
    {
        return [IntegrationAdaptersServiceProvider::class];
    }

    public function test_auto_applies_config_when_exactly_one_active_integration_exists(): void
    {
        $this->setBaselineMailConfig();

        $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-single'],
            metadata: ['message_stream' => 'broadcasts'],
        );

        // Trigger the resolving callback.
        app('mail.manager');

        $this->assertSame('srv-single', config('services.postmark.token'));
        $this->assertSame('broadcasts', config('services.postmark.message_stream_id'));
        $this->assertSame('postmark', config('mail.mailers.postmark.transport'));
        $this->assertSame('broadcasts', config('mail.mailers.postmark.message_stream_id'));
    }

    public function test_leaves_config_untouched_when_no_postmark_integrations_exist(): void
    {
        $this->setBaselineMailConfig(preExisting: 'env-token');

        app('mail.manager');

        $this->assertSame('env-token', config('services.postmark.token'));
    }

    public function test_leaves_config_untouched_when_multiple_active_integrations_exist(): void
    {
        $this->setBaselineMailConfig(preExisting: 'env-token');

        $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-a'],
        );

        $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-b'],
        );

        app('mail.manager');

        // With multiple integrations the auto-path opts out so the host
        // app can pick one explicitly via `useForMail()` per request.
        $this->assertSame('env-token', config('services.postmark.token'));
    }

    public function test_inactive_integrations_are_ignored_in_the_auto_count(): void
    {
        $this->setBaselineMailConfig();

        $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-active'],
        );

        $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-inactive'],
            attributes: ['is_active' => false],
        );

        app('mail.manager');

        // Two rows exist, but only the active one counts, so the auto
        // path still fires.
        $this->assertSame('srv-active', config('services.postmark.token'));
    }

    public function test_use_for_mail_applies_config_for_the_given_integration(): void
    {
        $this->setBaselineMailConfig(preExisting: 'env-token');

        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-tenant-1'],
            metadata: ['message_stream' => 'outbound'],
        );

        (new PostmarkProvider)->useForMail($integration);

        $this->assertSame('srv-tenant-1', config('services.postmark.token'));
        $this->assertSame('postmark', config('mail.mailers.postmark.transport'));
    }

    public function test_use_for_mail_replaces_config_when_called_twice(): void
    {
        $this->setBaselineMailConfig();

        $tenantA = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-tenant-a'],
        );

        $tenantB = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-tenant-b'],
        );

        $provider = new PostmarkProvider;
        $provider->useForMail($tenantA);
        $this->assertSame('srv-tenant-a', config('services.postmark.token'));

        $provider->useForMail($tenantB);
        $this->assertSame('srv-tenant-b', config('services.postmark.token'));
    }

    public function test_use_for_mail_throws_when_credentials_are_wrong_type(): void
    {
        $integration = Integration::create(['provider' => 'postmark', 'name' => 'Postmark']);

        $this->expectException(\RuntimeException::class);

        (new PostmarkProvider)->useForMail($integration);
    }

    public function test_use_for_mail_invalidates_facade_and_container_caches(): void
    {
        $this->setBaselineMailConfig(preExisting: 'env-token');

        // Touching the Mail facade populates both the container's
        // mail.manager singleton AND the facade's static
        // resolved-instance cache. Without invalidating both, anything
        // that already used Mail::* in this request would keep talking
        // to the old MailManager and miss the swap.
        $stale = Mail::getFacadeRoot();

        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-tenant-1'],
            metadata: ['message_stream' => 'broadcasts'],
        );

        (new PostmarkProvider)->useForMail($integration);

        // Re-resolving via the facade must yield a different instance.
        // Proves both the container singleton and the facade's
        // resolved-instance cache were cleared. (Container::resolved()
        // is sticky once the abstract has ever been resolved, so the
        // identity check is the reliable signal.)
        $fresh = Mail::getFacadeRoot();
        $this->assertNotSame($stale, $fresh);

        // And the new manager reads the new config.
        $this->assertSame('srv-tenant-1', config('services.postmark.token'));
        $this->assertSame('broadcasts', config('mail.mailers.postmark.message_stream_id'));
    }

    /**
     * Start each test with a known baseline so that "untouched" assertions
     * are meaningful. Optionally seed a pre-existing `.env`-style token to
     * prove the auto path doesn't overwrite it in the zero/many cases.
     */
    private function setBaselineMailConfig(?string $preExisting = null): void
    {
        config([
            'mail.default' => 'smtp',
            'services.postmark' => [
                'token' => $preExisting,
                'message_stream_id' => null,
            ],
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'localhost'],
        ]);
    }
}
