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
use Integrations\Contracts\HasIncrementalSync;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\RedactsRequestData;
use Integrations\Models\Integration;
use Integrations\Sync\SyncResult;
use InvalidArgumentException;

class ZendeskProvider implements HasHealthCheck, HasIncrementalSync, IntegrationProvider, RedactsRequestData
{
    #[\Override]
    public function name(): string
    {
        return 'Zendesk';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
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
    #[\Override]
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
    #[\Override]
    public function credentialDataClass(): string
    {
        return ZendeskCredentials::class;
    }

    /**
     * @return class-string<ZendeskMetadata>
     */
    #[\Override]
    public function metadataDataClass(): string
    {
        return ZendeskMetadata::class;
    }

    #[\Override]
    public function sync(Integration $integration): SyncResult
    {
        return $this->syncIncremental($integration, null);
    }

    #[\Override]
    public function syncIncremental(Integration $integration, mixed $cursor): SyncResult
    {
        if ($cursor !== null && ! is_string($cursor)) {
            throw new InvalidArgumentException('ZendeskProvider::syncIncremental() expects $cursor to be a string or null, got '.get_debug_type($cursor).'.');
        }

        $client = new ZendeskClient($integration);
        $since = is_string($cursor) ? Carbon::parse($cursor)->subHour() : Carbon::createFromTimestamp(0);

        $successCount = 0;
        $failureCount = 0;
        $earliestFailureAt = null;

        $client->tickets()->since($since, function (ZendeskTicketData $ticket, ?ZendeskUserData $user) use ($integration, &$successCount, &$failureCount, &$earliestFailureAt): void {
            try {
                ZendeskTicketSynced::dispatch($integration, $ticket, $user);
                $successCount++;
            } catch (\Throwable $e) {
                $failureCount++;
                if ($earliestFailureAt === null || $ticket->updated_at->isBefore($earliestFailureAt)) {
                    $earliestFailureAt = $ticket->updated_at;
                }

                Log::error('ZendeskProvider: Failed processing ticket: '.$e->getMessage(), [
                    'ticket_id' => $ticket->id,
                ]);
                ZendeskTicketSyncFailed::dispatch($integration, (string) $ticket->id, $e);
            }
        });

        $safeSyncedAt = $earliestFailureAt ?? now();

        $result = new SyncResult($successCount, $failureCount, $safeSyncedAt, cursor: $safeSyncedAt->toIso8601String());
        ZendeskSyncCompleted::dispatch($integration, $result);

        return $result;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function sensitiveRequestFields(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function sensitiveResponseFields(): array
    {
        return [];
    }

    #[\Override]
    public function defaultSyncInterval(): int
    {
        return 5;
    }

    #[\Override]
    public function defaultRateLimit(): int
    {
        return 100;
    }

    #[\Override]
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
