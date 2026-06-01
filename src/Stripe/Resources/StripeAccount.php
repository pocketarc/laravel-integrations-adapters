<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\StripeResource;
use Integrations\RequestContext;
use Stripe\Account;

class StripeAccount extends StripeResource
{
    /**
     * The account the API key authenticates as (Stripe `GET /v1/account`).
     */
    public function retrieve(): Account
    {
        $response = $this->integration
            ->at('account')
            ->get(fn (RequestContext $ctx): Account => $this->callStripe(
                $ctx,
                fn (): Account => $this->sdk()->accounts->retrieve(),
            ));

        return $this->expectInstance($response, Account::class);
    }
}
