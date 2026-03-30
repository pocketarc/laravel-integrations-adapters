<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Integrations\Adapters\Zendesk\Data\ZendeskTicketData;
use Integrations\Adapters\Zendesk\Data\ZendeskUserData;
use Integrations\Adapters\Zendesk\Events\ZendeskSyncCompleted;
use Integrations\Adapters\Zendesk\Events\ZendeskTicketSynced;
use Integrations\Adapters\Zendesk\Events\ZendeskTicketSyncFailed;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\HasScheduledSync;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Models\Integration;
use Integrations\Sync\SyncResult;

class ZendeskProvider implements HasHealthCheck, HasScheduledSync, IntegrationProvider
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

    public function sync(Integration $integration): SyncResult
    {
        $client = new ZendeskClient($integration);
        $since = $integration->last_synced_at ?? now()->subDay();

        $successCount = 0;
        $failureCount = 0;
        $earliestFailureAt = null;

        $client->getTicketsSince($since, function (ZendeskTicketData $ticket, ?ZendeskUserData $user) use ($integration, &$successCount, &$failureCount, &$earliestFailureAt): void {
            try {
                ZendeskTicketSynced::dispatch($integration, $ticket, $user);
                $successCount++;
            } catch (\Throwable $e) {
                $failureCount++;
                $updatedAt = Carbon::parse($ticket->updated_at);
                if ($earliestFailureAt === null || $updatedAt->isBefore($earliestFailureAt)) {
                    $earliestFailureAt = $updatedAt;
                }

                Log::error('ZendeskProvider: Failed processing ticket: '.$e->getMessage(), [
                    'ticket_id' => $ticket->id,
                ]);
                ZendeskTicketSyncFailed::dispatch($integration, (string) $ticket->id, $e);
            }
        });

        $safeSyncedAt = $earliestFailureAt ?? now();

        $result = new SyncResult($successCount, $failureCount, $safeSyncedAt);
        ZendeskSyncCompleted::dispatch($integration, $result);

        return $result;
    }

    public function defaultSyncInterval(): int
    {
        return 5;
    }

    public function defaultRateLimit(): ?int
    {
        return 100;
    }

    public function healthCheck(Integration $integration): bool
    {
        $credentials = $integration->credentials;
        $metadata = $integration->metadata;

        if (! $credentials instanceof ZendeskCredentials || ! $metadata instanceof ZendeskMetadata) {
            return false;
        }

        try {
            $baseUrl = $metadata->custom_domain ?? "https://{$metadata->subdomain}.zendesk.com";

            $response = Http::withBasicAuth("{$credentials->email}/token", $credentials->token)
                ->connectTimeout(5)
                ->timeout(10)
                ->get("{$baseUrl}/api/v2/users/me.json");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
