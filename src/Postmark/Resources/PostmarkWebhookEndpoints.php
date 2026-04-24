<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Resources;

use Illuminate\Support\Facades\Http;
use Integrations\Adapters\Postmark\Data\PostmarkWebhookEndpointData;
use Integrations\Adapters\Postmark\Data\PostmarkWebhookEndpointListResponse;
use Integrations\Adapters\Postmark\PostmarkCredentials;
use Integrations\Adapters\Postmark\PostmarkResource;
use RuntimeException;

use function Safe\json_decode;
use function Safe\json_encode;

/**
 * Manage webhook subscriptions registered against this Postmark server.
 * Most setups configure webhooks once via the Postmark dashboard; this
 * resource exists for the cases where you want to programmatically
 * provision them per tenant.
 *
 * Note on `list()`: the SDK's `getWebhookConfigurations()` strips the
 * Triggers/HttpAuth blocks from each entry — a known shape mismatch in
 * `wildbit/postmark-php` — so we go directly to the API for listings to
 * preserve the full record. Single-record fetches (`get()`) use the SDK
 * because that path is not affected.
 */
class PostmarkWebhookEndpoints extends PostmarkResource
{
    public function get(int $id): ?PostmarkWebhookEndpointData
    {
        return $this->executeWithErrorHandling(function () use ($id): ?PostmarkWebhookEndpointData {
            $result = $this->integration
                ->toAs("webhooks/{$id}", PostmarkWebhookEndpointData::class)
                ->get(function () use ($id): array {
                    $config = $this->sdk()->getWebhookConfiguration($id);

                    return $this->configToArray($config);
                });

            return $result instanceof PostmarkWebhookEndpointData ? $result : null;
        });
    }

    public function list(?string $messageStream = null): ?PostmarkWebhookEndpointListResponse
    {
        return $this->executeWithErrorHandling(function () use ($messageStream): ?PostmarkWebhookEndpointListResponse {
            $endpoint = 'webhooks'.($messageStream !== null ? "?MessageStream={$messageStream}" : '');

            $result = $this->integration
                ->toAs($endpoint, PostmarkWebhookEndpointListResponse::class)
                ->withData(['MessageStream' => $messageStream])
                ->get(function () use ($messageStream): array {
                    $token = $this->serverToken();

                    $query = $messageStream !== null ? ['MessageStream' => $messageStream] : [];

                    $body = Http::withHeaders([
                        'X-Postmark-Server-Token' => $token,
                        'Accept' => 'application/json',
                    ])
                        ->connectTimeout(5)
                        ->timeout(10)
                        ->get('https://api.postmarkapp.com/webhooks', $query)
                        ->throw()
                        ->json();

                    return is_array($body) ? $body : [];
                });

            return $result instanceof PostmarkWebhookEndpointListResponse ? $result : null;
        });
    }

    public function delete(int $id): bool
    {
        return $this->executeWithErrorHandling(function () use ($id): bool {
            $this->integration
                ->to("webhooks/{$id}")
                ->delete(function () use ($id): bool {
                    $this->sdk()->deleteWebhookConfiguration($id);

                    return true;
                });

            return true;
        }, default: false);
    }

    /**
     * @param  array{Username: string, Password: string}|null  $httpAuth
     * @param  array<int, array{Name: string, Value: string}>|null  $httpHeaders
     * @param  array<string, mixed>|null  $triggers  Postmark's nested triggers shape; pass null to inherit defaults
     */
    public function create(string $url, ?string $messageStream = null, ?array $httpAuth = null, ?array $httpHeaders = null, ?array $triggers = null): ?PostmarkWebhookEndpointData
    {
        $stream = $messageStream ?? $this->client->defaultMessageStream();

        return $this->executeWithErrorHandling(function () use ($url, $stream, $httpAuth, $httpHeaders, $triggers): ?PostmarkWebhookEndpointData {
            $payload = array_filter([
                'Url' => $url,
                'MessageStream' => $stream,
                'HttpAuth' => $httpAuth,
                'HttpHeaders' => $httpHeaders,
                'Triggers' => $triggers,
            ], static fn (mixed $value): bool => $value !== null);

            $result = $this->integration
                ->toAs('webhooks', PostmarkWebhookEndpointData::class)
                ->withData($payload)
                ->post(function () use ($payload): array {
                    $body = Http::withHeaders([
                        'X-Postmark-Server-Token' => $this->serverToken(),
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                        ->connectTimeout(5)
                        ->timeout(10)
                        ->post('https://api.postmarkapp.com/webhooks', $payload)
                        ->throw()
                        ->json();

                    return is_array($body) ? $body : [];
                });

            return $result instanceof PostmarkWebhookEndpointData ? $result : null;
        });
    }

    private function serverToken(): string
    {
        $credentials = $this->integration->credentials;

        if (! $credentials instanceof PostmarkCredentials) {
            throw new RuntimeException(sprintf(
                'Postmark integration (id=%s): credentials must be %s, got %s.',
                $this->integration->id ?? 'null',
                PostmarkCredentials::class,
                get_debug_type($credentials),
            ));
        }

        return $credentials->server_token;
    }

    /**
     * @return array<string, mixed>
     */
    private function configToArray(object $config): array
    {
        /** @var array<string, mixed> $base */
        $base = get_object_vars($config);

        // Triggers / HttpAuth are nested objects; flatten them so Spatie Data
        // can populate the matching ?array fields cleanly.
        $triggers = $base['Triggers'] ?? null;
        if (is_object($triggers)) {
            $base['Triggers'] = json_decode(json_encode($triggers), associative: true);
        }

        $httpAuth = $base['HttpAuth'] ?? null;
        if (is_object($httpAuth)) {
            $base['HttpAuth'] = json_decode(json_encode($httpAuth), associative: true);
        }

        return $base;
    }
}
