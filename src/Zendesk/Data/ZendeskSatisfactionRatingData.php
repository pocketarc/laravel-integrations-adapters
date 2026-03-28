<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Spatie\LaravelData\Data;

class ZendeskSatisfactionRatingData extends Data
{
    public function __construct(
        public readonly string $score,
    ) {}
}
