<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk;

use Illuminate\Support\Facades\Http;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Models\Integration;

class ZendeskProvider implements HasHealthCheck, IntegrationProvider
{
    public function name(): string
    {
        return 'Zendesk';
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialRules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'token' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataRules(): array
    {
        return [
            'subdomain' => ['required', 'string'],
            'custom_domain' => ['nullable', 'string', 'url'],
        ];
    }

    /**
     * @return class-string<ZendeskCredentials>
     */
    public function credentialDataClass(): string
    {
        return ZendeskCredentials::class;
    }

    /**
     * @return class-string<ZendeskMetadata>
     */
    public function metadataDataClass(): string
    {
        return ZendeskMetadata::class;
    }

    public function healthCheck(Integration $integration): bool
    {
        $credentials = $integration->credentials;
        $metadata = $integration->metadata;

        if (! $credentials instanceof ZendeskCredentials || ! $metadata instanceof ZendeskMetadata) {
            return false;
        }

        try {
            $response = Http::withBasicAuth("{$credentials->email}/token", $credentials->token)
                ->get("https://{$metadata->subdomain}.zendesk.com/api/v2/users/me.json");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
