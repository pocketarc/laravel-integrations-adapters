<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark;

use Spatie\LaravelData\Data;

/**
 * Credentials for a Postmark integration.
 *
 * `server_token` is the per-server API token (sent as the `X-Postmark-Server-Token`
 * header). It scopes every API call and every sent message to one Postmark "server".
 *
 * Postmark webhooks are not signed with an HMAC like Stripe's. The documented
 * options are HTTP Basic Auth on the webhook URL or IP allowlisting; we model
 * Basic Auth here so `verifyWebhookSignature()` can compare the inbound
 * Authorization header against stored credentials.
 *
 * `account_token` is reserved for the account-wide admin APIs (servers, domains,
 * sender signatures). No v1 resource uses it; it lives on the Data class so
 * adding admin features later doesn't require a credentials migration.
 */
class PostmarkCredentials extends Data
{
    public function __construct(
        public readonly string $server_token,
        public readonly ?string $webhook_username = null,
        public readonly ?string $webhook_password = null,
        public readonly ?string $account_token = null,
    ) {}
}
