<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark;

use Integrations\Adapters\Postmark\Resources\PostmarkBounces;
use Integrations\Adapters\Postmark\Resources\PostmarkMessages;
use Integrations\Adapters\Postmark\Resources\PostmarkServerStats;
use Integrations\Adapters\Postmark\Resources\PostmarkSuppressions;
use Integrations\Adapters\Postmark\Resources\PostmarkWebhookEndpoints;
use Integrations\Models\Integration;
use Postmark\PostmarkClient as PostmarkSdkClient;
use RuntimeException;

/**
 * Thin wrapper around `wildbit/postmark-php`'s PostmarkClient. Exposes one
 * accessor per resource we cover. Resource methods route through
 * `Integration::request()` so every call is logged, rate-limited, retried,
 * and contributes to health tracking. Postmark-specific Guzzle internals
 * stay below this line.
 *
 * Credentials are validated lazily (on first SDK access via `boot()`), so
 * a Client can be constructed for an integration row that has no
 * credentials yet (the failure mode is a clear runtime error at use time
 * rather than a confusing exception during DI resolution).
 *
 * Tests inject a pre-built PostmarkSdkClient via the constructor and use
 * Guzzle's MockHandler on it (matching the Zendesk pattern), since the
 * SDK uses Guzzle directly rather than Laravel's HTTP facade.
 */
class PostmarkClient
{
    private ?PostmarkSdkClient $sdk;

    private bool $booted = false;

    private ?PostmarkBounces $bounces = null;

    private ?PostmarkSuppressions $suppressions = null;

    private ?PostmarkMessages $messages = null;

    private ?PostmarkServerStats $serverStats = null;

    private ?PostmarkWebhookEndpoints $webhookEndpoints = null;

    public function __construct(
        private readonly Integration $integration,
        ?PostmarkSdkClient $sdk = null,
    ) {
        $this->sdk = $sdk;
    }

    public function bounces(): PostmarkBounces
    {
        return $this->bounces ??= new PostmarkBounces($this->integration, $this);
    }

    public function suppressions(): PostmarkSuppressions
    {
        return $this->suppressions ??= new PostmarkSuppressions($this->integration, $this);
    }

    public function messages(): PostmarkMessages
    {
        return $this->messages ??= new PostmarkMessages($this->integration, $this);
    }

    public function serverStats(): PostmarkServerStats
    {
        return $this->serverStats ??= new PostmarkServerStats($this->integration, $this);
    }

    public function webhookEndpoints(): PostmarkWebhookEndpoints
    {
        return $this->webhookEndpoints ??= new PostmarkWebhookEndpoints($this->integration, $this);
    }

    public function getSdkClient(): PostmarkSdkClient
    {
        $this->boot();

        if ($this->sdk === null) {
            throw new RuntimeException('Postmark SDK client not initialized.');
        }

        return $this->sdk;
    }

    /**
     * The default message stream from the integration's metadata. Resources
     * use this to scope stream-bound endpoints (Suppressions) when the caller
     * doesn't override.
     */
    public function defaultMessageStream(): string
    {
        $metadata = $this->integration->metadata;

        return $metadata instanceof PostmarkMetadata ? $metadata->message_stream : 'outbound';
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $credentials = $this->integration->credentials;

        if (! $credentials instanceof PostmarkCredentials) {
            throw new RuntimeException(sprintf(
                'Postmark integration (id=%s): credentials must be %s, got %s.',
                $this->integration->id ?? 'null',
                PostmarkCredentials::class,
                get_debug_type($credentials),
            ));
        }

        if ($this->sdk === null) {
            $this->sdk = new PostmarkSdkClient($credentials->server_token);
        }

        $this->booted = true;
    }
}
