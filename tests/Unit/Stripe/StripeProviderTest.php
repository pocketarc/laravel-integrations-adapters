<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Stripe;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Integrations\Adapters\Stripe\Events\StripeWebhookReceived;
use Integrations\Adapters\Stripe\StripeCredentials;
use Integrations\Adapters\Stripe\StripeMetadata;
use Integrations\Adapters\Stripe\StripeProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Contracts\HandlesWebhooks;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Models\Integration;
use Integrations\Testing\CreatesIntegration;

class StripeProviderTest extends TestCase
{
    use CreatesIntegration;

    public function test_implements_required_contracts(): void
    {
        $provider = new StripeProvider;

        $this->assertInstanceOf(IntegrationProvider::class, $provider);
        $this->assertInstanceOf(HandlesWebhooks::class, $provider);
        $this->assertInstanceOf(HasHealthCheck::class, $provider);
    }

    public function test_name(): void
    {
        $this->assertSame('Stripe', (new StripeProvider)->name());
    }

    public function test_credential_rules_gate_on_known_stripe_prefixes(): void
    {
        $rules = (new StripeProvider)->credentialRules();

        $this->assertArrayHasKey('api_key', $rules);
        $this->assertArrayHasKey('webhook_secret', $rules);
        $this->assertArrayHasKey('publishable_key', $rules);

        $apiKeyRule = $rules['api_key'];
        $this->assertIsArray($apiKeyRule);
        $this->assertContains('required', $apiKeyRule);
        $this->assertContains('starts_with:sk_live_,sk_test_', $apiKeyRule);
    }

    public function test_metadata_rules_are_empty(): void
    {
        $this->assertSame([], (new StripeProvider)->metadataRules());
    }

    public function test_data_classes(): void
    {
        $provider = new StripeProvider;

        $this->assertSame(StripeCredentials::class, $provider->credentialDataClass());
        $this->assertSame(StripeMetadata::class, $provider->metadataDataClass());
    }

    public function test_creates_integration_with_typed_credentials(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'stripe',
            providerClass: StripeProvider::class,
            credentials: [
                'api_key' => 'sk_test_abc',
                'webhook_secret' => 'whsec_zzz',
                'publishable_key' => 'pk_test_abc',
            ],
        );

        $this->assertInstanceOf(StripeCredentials::class, $integration->credentials);
        $this->assertSame('sk_test_abc', $integration->credentials->api_key);
        $this->assertSame('whsec_zzz', $integration->credentials->webhook_secret);
        $this->assertSame('pk_test_abc', $integration->credentials->publishable_key);
    }

    public function test_webhook_handlers_is_empty_so_handle_webhook_catches_all_events(): void
    {
        $this->assertSame([], (new StripeProvider)->webhookHandlers());
    }

    public function test_resolve_webhook_event_reads_type_from_payload(): void
    {
        $request = Request::create('/webhook', 'POST', content: (string) json_encode([
            'id' => 'evt_123',
            'type' => 'payment_intent.succeeded',
        ]));

        $this->assertSame('payment_intent.succeeded', (new StripeProvider)->resolveWebhookEvent($request));
    }

    public function test_resolve_webhook_event_returns_null_when_type_is_missing(): void
    {
        $request = Request::create('/webhook', 'POST', content: (string) json_encode(['id' => 'evt_123']));

        $this->assertNull((new StripeProvider)->resolveWebhookEvent($request));
    }

    public function test_resolve_webhook_event_returns_null_for_garbage_payload(): void
    {
        $request = Request::create('/webhook', 'POST', content: 'not-json');

        $this->assertNull((new StripeProvider)->resolveWebhookEvent($request));
    }

    public function test_webhook_delivery_id_reads_id_from_payload(): void
    {
        $request = Request::create('/webhook', 'POST', content: (string) json_encode([
            'id' => 'evt_delivery_1',
            'type' => 'x',
        ]));

        $this->assertSame('evt_delivery_1', (new StripeProvider)->webhookDeliveryId($request));
    }

    public function test_webhook_signature_rejects_when_credentials_are_missing(): void
    {
        // Bypass CreatesIntegration to produce a row with null credentials:
        // the happy-path helper types them and refuses null by design.
        $integration = Integration::create(['provider' => 'stripe', 'name' => 'Stripe']);

        $request = Request::create('/webhook', 'POST', content: '{}');

        $this->assertFalse((new StripeProvider)->verifyWebhookSignature($integration, $request));
    }

    public function test_webhook_signature_rejects_when_header_is_missing(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'stripe',
            providerClass: StripeProvider::class,
            credentials: [
                'api_key' => 'sk_test_abc',
                'webhook_secret' => 'whsec_zzz',
            ],
        );

        $request = Request::create('/webhook', 'POST', content: '{}');

        $this->assertFalse((new StripeProvider)->verifyWebhookSignature($integration, $request));
    }

    public function test_webhook_signature_verifies_a_correctly_signed_payload(): void
    {
        $secret = 'whsec_test_secret';
        $integration = $this->createIntegration(
            providerKey: 'stripe',
            providerClass: StripeProvider::class,
            credentials: [
                'api_key' => 'sk_test_abc',
                'webhook_secret' => $secret,
            ],
        );

        $payload = (string) json_encode(['id' => 'evt_123', 'type' => 'payment_intent.succeeded']);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        $header = "t={$timestamp},v1={$signature}";

        $request = Request::create('/webhook', 'POST', content: $payload);
        $request->headers->set('Stripe-Signature', $header);

        $this->assertTrue((new StripeProvider)->verifyWebhookSignature($integration, $request));
    }

    public function test_webhook_signature_rejects_a_tampered_payload(): void
    {
        $secret = 'whsec_test_secret';
        $integration = $this->createIntegration(
            providerKey: 'stripe',
            providerClass: StripeProvider::class,
            credentials: [
                'api_key' => 'sk_test_abc',
                'webhook_secret' => $secret,
            ],
        );

        $payload = (string) json_encode(['id' => 'evt_123', 'type' => 'payment_intent.succeeded']);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'wrong-secret');
        $header = "t={$timestamp},v1={$signature}";

        $request = Request::create('/webhook', 'POST', content: $payload);
        $request->headers->set('Stripe-Signature', $header);

        $this->assertFalse((new StripeProvider)->verifyWebhookSignature($integration, $request));
    }

    public function test_handle_webhook_dispatches_stripe_webhook_received_event(): void
    {
        Event::fake();

        $integration = $this->createIntegration(
            providerKey: 'stripe',
            providerClass: StripeProvider::class,
            credentials: [
                'api_key' => 'sk_test_abc',
                'webhook_secret' => 'whsec_zzz',
            ],
        );

        $payload = [
            'id' => 'evt_xyz',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123']],
        ];

        $request = Request::create('/webhook', 'POST', content: (string) json_encode($payload));

        $result = (new StripeProvider)->handleWebhook($integration, $request);

        $this->assertSame(['status' => 'dispatched', 'event' => 'payment_intent.succeeded'], $result);

        Event::assertDispatched(StripeWebhookReceived::class, function (StripeWebhookReceived $event) use ($integration, $payload): bool {
            return $event->integration->is($integration)
                && $event->eventType === 'payment_intent.succeeded'
                && $event->eventId === 'evt_xyz'
                && $event->payload === $payload;
        });
    }

    public function test_handle_webhook_tolerates_missing_fields(): void
    {
        Event::fake();

        $integration = $this->createIntegration(
            providerKey: 'stripe',
            providerClass: StripeProvider::class,
            credentials: [
                'api_key' => 'sk_test_abc',
                'webhook_secret' => 'whsec_zzz',
            ],
        );

        $request = Request::create('/webhook', 'POST', content: '{}');

        $result = (new StripeProvider)->handleWebhook($integration, $request);

        $this->assertSame(['status' => 'dispatched', 'event' => 'unknown'], $result);

        Event::assertDispatched(StripeWebhookReceived::class, function (StripeWebhookReceived $event): bool {
            return $event->eventType === 'unknown' && $event->eventId === '';
        });
    }

    public function test_health_check_returns_false_without_credentials(): void
    {
        $integration = Integration::create(['provider' => 'stripe', 'name' => 'Stripe']);

        $this->assertFalse((new StripeProvider)->healthCheck($integration));
    }
}
