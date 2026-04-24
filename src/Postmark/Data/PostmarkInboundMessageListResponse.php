<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class PostmarkInboundMessageListResponse extends Data
{
    /**
     * @param  Collection<int, PostmarkInboundMessageData>  $InboundMessages
     */
    public function __construct(
        public readonly int $TotalCount,
        #[DataCollectionOf(PostmarkInboundMessageData::class)]
        public readonly Collection $InboundMessages,
    ) {}
}
