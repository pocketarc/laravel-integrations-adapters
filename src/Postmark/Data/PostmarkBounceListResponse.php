<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * Page-level wrapper for the GET /bounces response. Pagination is offset/
 * count-based on Postmark's side; consumers add `count + offset` themselves
 * on the resource call. `TotalCount` is the unpaged total.
 */
class PostmarkBounceListResponse extends Data
{
    /**
     * @param  Collection<int, PostmarkBounceData>  $Bounces
     */
    public function __construct(
        public readonly int $TotalCount,
        #[DataCollectionOf(PostmarkBounceData::class)]
        public readonly Collection $Bounces,
    ) {}
}
