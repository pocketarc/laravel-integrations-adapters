<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\StripeResource;
use Stripe\Charge;
use Stripe\Collection;

class StripeCharges extends StripeResource
{
    public function retrieve(string $id): Charge
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("charges/{$id}")
            ->get(fn (): Charge => $this->sdk()->charges->retrieve($id));

        return $this->expectInstance($response, Charge::class);
    }

    public function capture(
        string $id,
        ?int $amount = null,
        ?string $receiptEmail = null,
        ?string $idempotencyKey = null,
    ): Charge {
        $this->assertId($id);

        $params = [];
        if ($amount !== null) {
            $this->assertPositive($amount, 'amount');
            $params['amount'] = $amount;
        }
        if ($receiptEmail !== null) {
            $params['receipt_email'] = $receiptEmail;
        }

        $idempotencyKey = $this->resolveIdempotencyKey($idempotencyKey);

        $response = $this->integration
            ->at("charges/{$id}/capture")
            ->withData($params)
            ->post(fn (): Charge => $this->sdk()->charges->capture($id, $params, ['idempotency_key' => $idempotencyKey]));

        return $this->expectInstance($response, Charge::class);
    }

    /**
     * @return Collection<Charge>
     */
    public function list(?string $customer = null, ?string $paymentIntent = null, ?int $limit = null): Collection
    {
        $params = [];
        if ($customer !== null) {
            $this->assertId($customer);
            $params['customer'] = $customer;
        }
        if ($paymentIntent !== null) {
            $this->assertId($paymentIntent);
            $params['payment_intent'] = $paymentIntent;
        }
        if ($limit !== null) {
            $this->assertPositive($limit, 'limit');
            $params['limit'] = $limit;
        }

        $response = $this->integration
            ->at('charges')
            ->withData($params)
            ->get(fn (): Collection => $this->sdk()->charges->all($params));

        return $this->expectInstance($response, Collection::class);
    }
}
