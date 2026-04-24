<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Integrations\Adapters\Postmark\Data\PostmarkBounceWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkClickWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkDeliveryWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkInboundWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkOpenWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkSpamComplaintWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkSubscriptionChangeWebhookData;
use Integrations\Adapters\Postmark\Enums\PostmarkWebhookRecordType;
use Integrations\Adapters\Postmark\Events\PostmarkBounceReceived;
use Integrations\Adapters\Postmark\Events\PostmarkClickReceived;
use Integrations\Adapters\Postmark\Events\PostmarkDeliveryReceived;
use Integrations\Adapters\Postmark\Events\PostmarkInboundReceived;
use Integrations\Adapters\Postmark\Events\PostmarkOpenReceived;
use Integrations\Adapters\Postmark\Events\PostmarkSpamComplaintReceived;
use Integrations\Adapters\Postmark\Events\PostmarkSubscriptionChangeReceived;
use Integrations\Adapters\Postmark\Events\PostmarkWebhookReceived;
use Integrations\Contracts\HandlesWebhooks;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Models\Integration;
use RuntimeException;
use Safe\Exceptions\JsonException;
use Safe\Exceptions\UrlException;

use function Safe\base64_decode;
use function Safe\json_decode;

/**
 * Postmark integration provider. Three jobs:
 *
 * 1. Standard adapter surface — credential typing, validation rules, health
 *    check via the `/server` endpoint.
 * 2. Webhook ingestion — Postmark doesn't sign with HMAC, so signature
 *    verification compares the inbound `Authorization: Basic` header against
 *    stored credentials. After verification, `handleWebhook()` always fires
 *    the generic `PostmarkWebhookReceived` event and additionally fires the
 *    typed event matching the payload's `RecordType`.
 * 3. Mailer credentials bridge — `registerMailerOverride()` is called from
 *    the package service provider's `boot()` and lazy-wires the host app's
 *    Postmark mailer config from a single registered Postmark integration
 *    record. Multi-tenant apps with several Postmark integrations should
 *    call `useForMail()` per request to swap which one is active.
 */
class PostmarkProvider implements HandlesWebhooks, HasHealthCheck, IntegrationProvider
{
    #[\Override]
    public function name(): string
    {
        return 'Postmark';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function credentialRules(): array
    {
        return [
            'server_token' => ['required', 'string'],
            'webhook_username' => ['nullable', 'string'],
            'webhook_password' => ['nullable', 'string'],
            'account_token' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function metadataRules(): array
    {
        return [
            'message_stream' => ['nullable', 'string'],
            'server_name' => ['nullable', 'string'],
        ];
    }

    /**
     * @return class-string<PostmarkCredentials>
     */
    #[\Override]
    public function credentialDataClass(): string
    {
        return PostmarkCredentials::class;
    }

    /**
     * @return class-string<PostmarkMetadata>
     */
    #[\Override]
    public function metadataDataClass(): string
    {
        return PostmarkMetadata::class;
    }

    #[\Override]
    public function healthCheck(Integration $integration): bool
    {
        $credentials = $integration->credentials;
        if (! $credentials instanceof PostmarkCredentials) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'X-Postmark-Server-Token' => $credentials->server_token,
                'Accept' => 'application/json',
            ])
                ->connectTimeout(5)
                ->timeout(10)
                ->get('https://api.postmarkapp.com/server');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verify the webhook by comparing the inbound `Authorization: Basic` header
     * against stored credentials. Postmark's webhook security model is HTTP
     * Basic Auth on the URL (or IP allowlisting) — there is no per-request
     * HMAC. Returns false if either credential side is missing so an
     * unconfigured integration fails closed.
     */
    #[\Override]
    public function verifyWebhookSignature(Integration $integration, Request $request): bool
    {
        $credentials = $integration->credentials;
        if (! $credentials instanceof PostmarkCredentials) {
            return false;
        }

        if ($credentials->webhook_username === null || $credentials->webhook_password === null) {
            return false;
        }

        $header = $request->header('Authorization');
        if (! is_string($header) || ! str_starts_with($header, 'Basic ')) {
            return false;
        }

        try {
            $decoded = base64_decode(mb_substr($header, 6), strict: true);
        } catch (UrlException) {
            return false;
        }

        $expected = $credentials->webhook_username.':'.$credentials->webhook_password;

        return hash_equals($expected, $decoded);
    }

    #[\Override]
    public function resolveWebhookEvent(Request $request): ?string
    {
        $payload = $this->decodePayload($request);

        $type = $payload['RecordType'] ?? null;

        return is_string($type) ? $type : null;
    }

    /**
     * @return array<string, class-string|callable(Integration, Request): mixed|array{class-string, string}>
     */
    #[\Override]
    public function webhookHandlers(): array
    {
        return [];
    }

    /**
     * Returning null lets the core fall back to a payload-hash dedup key.
     * Postmark doesn't ship a stable per-delivery header, and using just the
     * payload's `MessageID` would collapse legitimate distinct events for
     * the same message (multiple opens, multiple clicks).
     */
    #[\Override]
    public function webhookDeliveryId(Request $request): ?string
    {
        return null;
    }

    /**
     * @return array{status: string, event: string}
     */
    #[\Override]
    public function handleWebhook(Integration $integration, Request $request): array
    {
        $payload = $this->decodePayload($request);

        $rawType = is_string($payload['RecordType'] ?? null) ? $payload['RecordType'] : 'unknown';
        $messageId = is_string($payload['MessageID'] ?? null) ? $payload['MessageID'] : '';

        PostmarkWebhookReceived::dispatch($integration, $rawType, $messageId, $payload);

        $type = PostmarkWebhookRecordType::tryFrom($rawType);
        if ($type === null) {
            if ($rawType !== 'unknown') {
                Log::warning('PostmarkProvider: unrecognised RecordType, no typed event dispatched.', [
                    'integration_id' => $integration->id,
                    'record_type' => $rawType,
                ]);
            }

            return ['status' => 'dispatched', 'event' => $rawType];
        }

        $this->dispatchTypedEvent($integration, $type, $payload);

        return ['status' => 'dispatched', 'event' => $type->value];
    }

    /**
     * Lazy mailer-config bridge. Hooks the container's `mail.manager`
     * resolution so the database lookup only happens when the host app
     * actually uses mail — we don't want to query Integrations on every
     * request just to handle the case where mail might be sent.
     *
     * Skips silently for zero or multiple Postmark integrations: zero
     * leaves the host app's existing config alone; multiple means a
     * multi-tenant setup where the host must pick per-request via
     * `useForMail()`.
     */
    public static function registerMailerOverride(): void
    {
        $applied = false;

        app()->resolving('mail.manager', function () use (&$applied): void {
            if ($applied) {
                return;
            }
            $applied = true;

            self::autoApplySingleIntegration();
        });
    }

    private static function autoApplySingleIntegration(): void
    {
        try {
            $integrations = Integration::query()
                ->forProvider('postmark')
                ->active()
                ->get();
        } catch (\Throwable) {
            return;
        }

        if ($integrations->count() !== 1) {
            return;
        }

        $integration = $integrations->first();
        if ($integration === null) {
            return;
        }

        self::applyMailConfig($integration);
    }

    /**
     * Runtime escape hatch for multi-integration setups: call this before
     * `Mail::send()` to point Laravel's Postmark mailer at this specific
     * integration's credentials for the rest of the current request.
     */
    public function useForMail(Integration $integration): void
    {
        self::applyMailConfig($integration);
    }

    private static function applyMailConfig(Integration $integration): void
    {
        $credentials = $integration->credentials;
        $metadata = $integration->metadata;

        if (! $credentials instanceof PostmarkCredentials) {
            throw new RuntimeException(sprintf(
                'Postmark integration (id=%s): credentials must be %s, got %s.',
                $integration->id ?? 'null',
                PostmarkCredentials::class,
                get_debug_type($credentials),
            ));
        }

        $stream = $metadata instanceof PostmarkMetadata ? $metadata->message_stream : 'outbound';

        config([
            'services.postmark.token' => $credentials->server_token,
            'services.postmark.message_stream_id' => $stream,
            'mail.mailers.postmark.transport' => 'postmark',
            'mail.mailers.postmark.message_stream_id' => $stream,
        ]);

        if (app()->resolved('mail.manager')) {
            app()->forgetInstance('mail.manager');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchTypedEvent(Integration $integration, PostmarkWebhookRecordType $type, array $payload): void
    {
        if ($type === PostmarkWebhookRecordType::Delivery) {
            PostmarkDeliveryReceived::dispatch($integration, PostmarkDeliveryWebhookData::from($payload));

            return;
        }

        if ($type === PostmarkWebhookRecordType::Bounce) {
            PostmarkBounceReceived::dispatch($integration, PostmarkBounceWebhookData::from($payload));

            return;
        }

        if ($type === PostmarkWebhookRecordType::Open) {
            PostmarkOpenReceived::dispatch($integration, PostmarkOpenWebhookData::from($payload));

            return;
        }

        if ($type === PostmarkWebhookRecordType::Click) {
            PostmarkClickReceived::dispatch($integration, PostmarkClickWebhookData::from($payload));

            return;
        }

        if ($type === PostmarkWebhookRecordType::SpamComplaint) {
            PostmarkSpamComplaintReceived::dispatch($integration, PostmarkSpamComplaintWebhookData::from($payload));

            return;
        }

        if ($type === PostmarkWebhookRecordType::SubscriptionChange) {
            PostmarkSubscriptionChangeReceived::dispatch($integration, PostmarkSubscriptionChangeWebhookData::from($payload));

            return;
        }

        // Inbound is the only remaining case in PostmarkWebhookRecordType.
        PostmarkInboundReceived::dispatch($integration, PostmarkInboundWebhookData::from($payload));
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
