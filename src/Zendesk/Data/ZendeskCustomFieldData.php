<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Spatie\LaravelData\Data;

class ZendeskCustomFieldData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly mixed $value,
    ) {}
}
