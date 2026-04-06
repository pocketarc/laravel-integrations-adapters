<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Spatie\LaravelData\Data;

class ZendeskPaginationMeta extends Data
{
    public function __construct(
        public readonly bool $has_more,
        public readonly ?string $after_cursor = null,
    ) {}
}
