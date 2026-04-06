<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class ZendeskSearchResponse extends Data
{
    /**
     * @param  Collection<int, ZendeskTicketData>  $results
     * @param  Collection<int, ZendeskUserData>  $users
     */
    public function __construct(
        #[DataCollectionOf(ZendeskTicketData::class)]
        public readonly Collection $results,
        #[DataCollectionOf(ZendeskUserData::class)]
        public readonly Collection $users = new Collection,
        public readonly ?string $next_page = null,
    ) {}
}
