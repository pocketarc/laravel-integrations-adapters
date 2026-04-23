<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe;

use Integrations\Models\Integration;
use Stripe\StripeClient as StripeSdkClient;

abstract class StripeResource
{
    public function __construct(
        protected readonly Integration $integration,
        protected readonly StripeClient $client,
    ) {}

    protected function sdk(): StripeSdkClient
    {
        return $this->client->getSdkClient();
    }
}
