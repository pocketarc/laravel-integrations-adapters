<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class PostmarkWebhookEndpointListResponse extends Data
{
    /**
     * @param  Collection<int, PostmarkWebhookEndpointData>  $Webhooks
     */
    public function __construct(
        #[DataCollectionOf(PostmarkWebhookEndpointData::class)]
        public readonly Collection $Webhooks,
    ) {}
}
