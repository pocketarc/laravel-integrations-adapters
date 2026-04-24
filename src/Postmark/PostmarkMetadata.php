<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark;

use Spatie\LaravelData\Data;

/**
 * Metadata for a Postmark integration. The server token already scopes API
 * calls to one server; the only thing worth carrying here is the message
 * stream the host app should use when sending mail or operating on
 * stream-scoped resources (Suppressions). Defaults to Postmark's standard
 * transactional stream so single-stream setups need no configuration.
 *
 * `server_name` is purely for display in admin UIs; Postmark itself
 * ignores it.
 */
class PostmarkMetadata extends Data
{
    public function __construct(
        public readonly string $message_stream = 'outbound',
        public readonly ?string $server_name = null,
    ) {}
}
