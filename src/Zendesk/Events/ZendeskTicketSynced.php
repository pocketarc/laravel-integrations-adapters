<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Adapters\Zendesk\Data\ZendeskTicketData;
use Integrations\Adapters\Zendesk\Data\ZendeskUserData;
use Integrations\Models\Integration;

class ZendeskTicketSynced
{
    use Dispatchable;

    public function __construct(
        public readonly Integration $integration,
        public readonly ZendeskTicketData $ticket,
        public readonly ?ZendeskUserData $user,
    ) {}
}
