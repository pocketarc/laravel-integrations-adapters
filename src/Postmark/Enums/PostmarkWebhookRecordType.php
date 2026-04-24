<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Enums;

/**
 * The seven `RecordType` values Postmark uses on its webhook payloads. Names
 * match Postmark's exact casing so we can `tryFrom($payload['RecordType'])`
 * without normalising first. An incoming payload with a `RecordType` outside
 * this set yields `null` and the provider falls through to the generic event.
 */
enum PostmarkWebhookRecordType: string
{
    case Delivery = 'Delivery';
    case Bounce = 'Bounce';
    case Open = 'Open';
    case Click = 'Click';
    case SpamComplaint = 'SpamComplaint';
    case SubscriptionChange = 'SubscriptionChange';
    case Inbound = 'Inbound';
}
