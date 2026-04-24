<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * Wrapper for GET /message-streams/{stream}/suppressions/dump. Postmark
 * returns the entire suppression list for a stream — there's no
 * pagination — so the only useful field beyond the entries is the count
 * for sanity-checking large dumps.
 */
class PostmarkSuppressionListResponse extends Data
{
    /**
     * @param  Collection<int, PostmarkSuppressionData>  $Suppressions
     */
    public function __construct(
        #[DataCollectionOf(PostmarkSuppressionData::class)]
        public readonly Collection $Suppressions,
    ) {}
}
