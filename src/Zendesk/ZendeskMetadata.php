<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk;

use Spatie\LaravelData\Data;

class ZendeskMetadata extends Data
{
    public function __construct(
        public readonly string $subdomain,
        public readonly ?string $custom_domain = null,
    ) {}
}
