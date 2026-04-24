<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Enums;

/**
 * Postmark's bounce taxonomy. Names mirror the API's `Type` field exactly so
 * we can `from()` straight off a payload. Documented at
 * https://postmarkapp.com/developer/api/bounce-api#bounce-types.
 *
 * Not every consumer cares about every type; the point of typing this is to
 * make the common ones (HardBounce, SoftBounce, SpamComplaint) safe to
 * `match` against without typoing string literals.
 */
enum PostmarkBounceType: string
{
    case HardBounce = 'HardBounce';
    case Transient = 'Transient';
    case Unsubscribe = 'Unsubscribe';
    case Subscribe = 'Subscribe';
    case AutoResponder = 'AutoResponder';
    case AddressChange = 'AddressChange';
    case DnsError = 'DnsError';
    case SpamNotification = 'SpamNotification';
    case OpenRelayTest = 'OpenRelayTest';
    case Unknown = 'Unknown';
    case SoftBounce = 'SoftBounce';
    case VirusNotification = 'VirusNotification';
    case ChallengeVerification = 'ChallengeVerification';
    case BadEmailAddress = 'BadEmailAddress';
    case SpamComplaint = 'SpamComplaint';
    case ManuallyDeactivated = 'ManuallyDeactivated';
    case Unconfirmed = 'Unconfirmed';
    case Blocked = 'Blocked';
    case SMTPApiError = 'SMTPApiError';
    case InboundError = 'InboundError';
    case DMARCPolicy = 'DMARCPolicy';
    case TemplateRenderingFailed = 'TemplateRenderingFailed';
}
