<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Spatie\LaravelData\Data;

class ZendeskViaData extends Data
{
    /**
     * @param  array<string, mixed>  $source
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $source = [],
    ) {}
}
