<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class PostmarkOutboundMessageListResponse extends Data
{
    /**
     * @param  Collection<int, PostmarkOutboundMessageData>  $Messages
     */
    public function __construct(
        public readonly int $TotalCount,
        #[DataCollectionOf(PostmarkOutboundMessageData::class)]
        public readonly Collection $Messages,
    ) {}
}
