<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\StripeResource;
use InvalidArgumentException;
use Stripe\Collection;
use Stripe\Refund;

class StripeRefunds extends StripeResource
{
    /**
     * Refund against a charge or a payment intent. Exactly one of `paymentIntent`
     * or `charge` must be provided.
     *
     * An auto-generated idempotency key covers core retries inside one call,
     * not re-issues from a queued job. Pass a stable key (e.g. derived from
     * the originating domain event) when you need cross-invocation safety.
     *
     * @param  array<string, string>|null  $metadata
     */
    public function create(
        ?string $paymentIntent = null,
        ?string $charge = null,
        ?int $amount = null,
        ?string $reason = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
    ): Refund {
        if (($paymentIntent === null) === ($charge === null)) {
            throw new InvalidArgumentException(
                'StripeRefunds::create requires exactly one of $paymentIntent or $charge.',
            );
        }

        $params = [];
        if ($paymentIntent !== null) {
            $this->assertId($paymentIntent);
            $params['payment_intent'] = $paymentIntent;
        }
        if ($charge !== null) {
            $this->assertId($charge);
            $params['charge'] = $charge;
        }
        if ($amount !== null) {
            $this->assertPositive($amount, 'amount');
            $params['amount'] = $amount;
        }
        if ($reason !== null) {
            $params['reason'] = $reason;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        $idempotencyKey = $this->resolveIdempotencyKey($idempotencyKey);

        $response = $this->integration
            ->to('refunds')
            ->withData($params)
            ->post(fn (): Refund => $this->sdk()->refunds->create($params, ['idempotency_key' => $idempotencyKey]));

        return $this->expectInstance($response, Refund::class);
    }

    public function retrieve(string $id): Refund
    {
        $this->assertId($id);

        $response = $this->integration
            ->to("refunds/{$id}")
            ->get(fn (): Refund => $this->sdk()->refunds->retrieve($id));

        return $this->expectInstance($response, Refund::class);
    }

    /**
     * @return Collection<Refund>
     */
    public function list(?string $paymentIntent = null, ?string $charge = null, ?int $limit = null): Collection
    {
        $params = [];
        if ($paymentIntent !== null) {
            $this->assertId($paymentIntent);
            $params['payment_intent'] = $paymentIntent;
        }
        if ($charge !== null) {
            $this->assertId($charge);
            $params['charge'] = $charge;
        }
        if ($limit !== null) {
            $this->assertPositive($limit, 'limit');
            $params['limit'] = $limit;
        }

        $response = $this->integration
            ->to('refunds')
            ->withData($params)
            ->get(fn (): Collection => $this->sdk()->refunds->all($params));

        return $this->expectInstance($response, Collection::class);
    }
}
