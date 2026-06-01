<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Integrations\Adapters\Zendesk\Data\ZendeskTicketData;
use Integrations\Adapters\Zendesk\Data\ZendeskUserData;
use Integrations\Adapters\Zendesk\Events\ZendeskTicketSynced;
use Integrations\Concerns\ReducesCheckpointsByMax;
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\HasIncrementalSync;
use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\RedactsRequestData;
use Integrations\Data\AuthenticatedUser;
use Integrations\Enums\FailureClass;
use Integrations\Models\Integration;
use Integrations\RateLimit;
use Integrations\Sync\SyncSession;
use InvalidArgumentException;
use Throwable;

class ZendeskProvider implements ClassifiesFailures, HasHealthCheck, HasIncrementalSync, IdentifiesAuthenticatedUser, IntegrationProvider, RedactsRequestData
{
    use ReducesCheckpointsByMax;

    #[\Override]
    public function classifyFailure(Throwable $e): ?FailureClass
    {
        // The Zendesk SDK wraps the Guzzle RequestException as the previous
        // exception, which core's chain-walking status extraction already
        // reads, so 4xx/5xx/429 are deferred to the default classifier. A bare
        // connection error has no status, so map it to an upstream fault here.
        if ($e instanceof ConnectException) {
            return FailureClass::Upstream;
        }

        return null;
    }

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
    public function sync(Integration $integration, SyncSession $session): void
    {
        // A full re-sync ignores the cursor and enumerates from the epoch.
        // The framework only calls this for non-incremental providers; for
        // Zendesk it's a fallback, since the provider is HasIncrementalSync.
        $this->enumerate($integration, $session, Carbon::createFromTimestamp(0));
    }

    #[\Override]
    public function syncIncremental(Integration $integration, SyncSession $session): void
    {
        $cursor = $session->cursor();

        if ($cursor === null || $cursor === '') {
            $since = Carbon::createFromTimestamp(0);
        } else {
            if (! is_string($cursor)) {
                throw new InvalidArgumentException('ZendeskProvider::syncIncremental() expects the cursor to be a string or null, got '.get_debug_type($cursor).'.');
            }

            $parsed = self::parseTimestamp($cursor);
            if ($parsed === null) {
                throw new InvalidArgumentException("ZendeskProvider::syncIncremental() received an unparseable cursor: '{$cursor}'.");
            }

            // Subtract a 1-hour overlap buffer to catch tickets updated between
            // runs. The framework's cursor advance is monotonic, so
            // re-presenting items inside that window can't regress progress.
            $since = $parsed->subHour();
        }

        $this->enumerate($integration, $session, $since);
    }

    private function enumerate(Integration $integration, SyncSession $session, Carbon $since): void
    {
        $client = $this->makeClient($integration);

        $client->tickets()->since($since, function (ZendeskTicketData $ticket, ?ZendeskUserData $user) use ($integration, $session): void {
            $session->dispatch(
                new ZendeskTicketSynced($integration, $ticket, $user),
                checkpointValue: $ticket->updated_at->toIso8601String(),
                externalId: (string) $ticket->id,
            );
        });
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
    public function defaultRateLimit(): RateLimit
    {
        // Zendesk enforces its per-minute limit as a rolling window.
        return RateLimit::perMinute(100)->sliding();
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
        } catch (Throwable) {
            return false;
        }
    }

    #[\Override]
    public function authenticatedUser(Integration $integration): AuthenticatedUser
    {
        $user = $this->makeClient($integration)->users()->authenticated();

        return new AuthenticatedUser(
            id: (string) $user->id,
            username: $user->email,
            name: $user->name,
            email: $user->email,
            raw: $user->original ?? [],
        );
    }

    protected function makeClient(Integration $integration): ZendeskClient
    {
        return new ZendeskClient($integration);
    }

    private static function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d\TH:i:sP', $value);
        } catch (Throwable) {
            return null;
        }
    }
}
