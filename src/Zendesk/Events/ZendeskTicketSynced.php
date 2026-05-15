<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Events;

use Integrations\Adapters\Zendesk\Data\ZendeskTicketData;
use Integrations\Adapters\Zendesk\Data\ZendeskUserData;
use Integrations\Models\Integration;
use Integrations\Sync\SyncItemEvent;

class ZendeskTicketSynced extends SyncItemEvent
{
    public function __construct(
        public readonly Integration $integration,
        public readonly ZendeskTicketData $ticket,
        public readonly ?ZendeskUserData $user,
    ) {}
}
