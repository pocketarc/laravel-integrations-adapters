<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe;

use Illuminate\Http\Request;
use Integrations\Adapters\Stripe\Events\StripeWebhookReceived;
use Integrations\Contracts\HandlesWebhooks;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\SupportsIdempotency;
use Integrations\Models\Integration;
use Safe\Exceptions\JsonException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

use function Safe\json_decode;

/**
 * Stripe integration provider. Handles credential typing, webhook signature
 * verification, health checks, and a generic event pipeline that dispatches
 * `StripeWebhookReceived` for every verified delivery. Consumers listen for
 * that event and route by type to their own Actions; this adapter stays
 * free of domain-specific routing.
 *
 * For making API calls, resolve a `StripeClient` for the Integration and use
 * its resource accessors (`->paymentIntents()`, `->refunds()`, etc.) rather
 * than reaching into `\Stripe\*` types.
 */
class StripeProvider implements HandlesWebhooks, HasHealthCheck, IntegrationProvider, SupportsIdempotency
{
    #[\Override]
    public function name(): string
    {
        return 'Stripe';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function credentialRules(): array
    {
        return [
            'api_key' => ['required', 'string', 'starts_with:sk_live_,sk_test_'],
            'webhook_secret' => ['required', 'string', 'starts_with:whsec_'],
            'publishable_key' => ['nullable', 'string', 'starts_with:pk_live_,pk_test_'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function metadataRules(): array
    {
        return [];
    }

    /**
     * @return class-string<StripeCredentials>
     */
    #[\Override]
    public function credentialDataClass(): string
    {
        return StripeCredentials::class;
    }

    /**
     * @return class-string<StripeMetadata>
     */
    #[\Override]
    public function metadataDataClass(): string
    {
        return StripeMetadata::class;
    }

    /**
     * @return array{status: string, event: string}
     */
    #[\Override]
    public function handleWebhook(Integration $integration, Request $request): array
    {
        $payload = $this->decodePayload($request);

        $type = is_string($payload['type'] ?? null) ? $payload['type'] : 'unknown';
        $id = is_string($payload['id'] ?? null) ? $payload['id'] : '';

        StripeWebhookReceived::dispatch($integration, $type, $id, $payload);

        return ['status' => 'dispatched', 'event' => $type];
    }

    #[\Override]
    public function verifyWebhookSignature(Integration $integration, Request $request): bool
    {
        $credentials = $integration->credentials;
        if (! $credentials instanceof StripeCredentials) {
            return false;
        }

        $signature = $request->header('Stripe-Signature');
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        try {
            Webhook::constructEvent(
                $request->getContent(),
                $signature,
                $credentials->webhook_secret,
            );

            return true;
        } catch (SignatureVerificationException) {
            return false;
        }
    }

    #[\Override]
    public function resolveWebhookEvent(Request $request): ?string
    {
        $payload = $this->decodePayload($request);

        $type = $payload['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /**
     * @return array<string, class-string|callable(Integration, Request): mixed|array{class-string, string}>
     */
    #[\Override]
    public function webhookHandlers(): array
    {
        // Empty by design: consumers listen to StripeWebhookReceived and
        // route by type there. Returning an empty array makes the core
        // package fall back to `handleWebhook()` for every event.
        return [];
    }

    #[\Override]
    public function webhookDeliveryId(Request $request): ?string
    {
        $payload = $this->decodePayload($request);

        $id = $payload['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    #[\Override]
    public function healthCheck(Integration $integration): bool
    {
        try {
            (new StripeClient($integration))->getSdkClient()->balance->retrieve();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(Request $request): array
    {
        try {
            $decoded = json_decode($request->getContent(), associative: true);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
