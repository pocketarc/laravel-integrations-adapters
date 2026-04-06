<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class ZendeskCommentPageResponse extends Data
{
    /**
     * @param  Collection<int, ZendeskCommentData>  $comments
     */
    public function __construct(
        #[DataCollectionOf(ZendeskCommentData::class)]
        public readonly Collection $comments,
        public readonly ZendeskPaginationMeta $meta,
    ) {}
}
