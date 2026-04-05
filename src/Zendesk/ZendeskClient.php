<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk;

use Integrations\Adapters\Zendesk\Resources\ZendeskAttachments;
use Integrations\Adapters\Zendesk\Resources\ZendeskComments;
use Integrations\Adapters\Zendesk\Resources\ZendeskTickets;
use Integrations\Adapters\Zendesk\Resources\ZendeskUsers;
use Integrations\Models\Integration;
use RuntimeException;
use Zendesk\API\HttpClient as ZendeskAPI;

class ZendeskClient
{
    private ?ZendeskAPI $sdk;

    private bool $booted = false;

    private ?ZendeskTickets $tickets = null;

    private ?ZendeskComments $comments = null;

    private ?ZendeskUsers $users = null;

    private ?ZendeskAttachments $attachments = null;

    public function __construct(
        private readonly Integration $integration,
        ?ZendeskAPI $sdk = null,
    ) {
        $this->sdk = $sdk;
    }

    public function tickets(): ZendeskTickets
    {
        return $this->tickets ??= new ZendeskTickets($this->integration, $this);
    }

    public function comments(): ZendeskComments
    {
        return $this->comments ??= new ZendeskComments($this->integration, $this);
    }

    public function users(): ZendeskUsers
    {
        return $this->users ??= new ZendeskUsers($this->integration, $this);
    }

    public function attachments(): ZendeskAttachments
    {
        return $this->attachments ??= new ZendeskAttachments($this->integration, $this);
    }

    public function getSdkClient(): ZendeskAPI
    {
        $this->boot();

        if ($this->sdk === null) {
            throw new RuntimeException('Zendesk SDK client not initialized.');
        }

        return $this->sdk;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $credentials = $this->integration->credentials;
        $metadata = $this->integration->metadata;

        if (! $credentials instanceof ZendeskCredentials) {
            throw new RuntimeException(sprintf(
                'Zendesk integration (id=%s): credentials must be %s, got %s.',
                $this->integration->id ?? 'null',
                ZendeskCredentials::class,
                get_debug_type($credentials),
            ));
        }

        if (! $metadata instanceof ZendeskMetadata) {
            throw new RuntimeException(sprintf(
                'Zendesk integration (id=%s): metadata must be %s, got %s.',
                $this->integration->id ?? 'null',
                ZendeskMetadata::class,
                get_debug_type($metadata),
            ));
        }

        if ($this->sdk === null) {
            $this->sdk = new ZendeskAPI($metadata->subdomain);
            $this->sdk->setAuth('basic', [
                'username' => $credentials->email,
                'token' => $credentials->token,
            ]);
        }

        $this->booted = true;
    }
}
