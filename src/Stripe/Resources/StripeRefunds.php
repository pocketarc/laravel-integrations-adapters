<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Illuminate\Support\Str;
use Integrations\Adapters\Stripe\Data\StripeRefundData;
use Integrations\Adapters\Stripe\StripeResource;
use InvalidArgumentException;

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
    ): StripeRefundData {
        if (($paymentIntent === null) === ($charge === null)) {
            throw new InvalidArgumentException(
                'StripeRefunds::create requires exactly one of $paymentIntent or $charge.',
            );
        }

        $params = [];
        if ($paymentIntent !== null) {
            $params['payment_intent'] = $paymentIntent;
        }
        if ($charge !== null) {
            $params['charge'] = $charge;
        }
        if ($amount !== null) {
            $params['amount'] = $amount;
        }
        if ($reason !== null) {
            $params['reason'] = $reason;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        $idempotencyKey ??= Str::uuid()->toString();

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('refunds')
            ->withData($params)
            ->post(fn (): array => $this->sdk()->refunds->create($params, ['idempotency_key' => $idempotencyKey])->toArray());

        return StripeRefundData::from($response);
    }

    public function retrieve(string $id): StripeRefundData
    {
        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("refunds/{$id}")
            ->get(fn (): array => $this->sdk()->refunds->retrieve($id)->toArray());

        return StripeRefundData::from($response);
    }

    /**
     * @return list<StripeRefundData>
     */
    public function list(?string $paymentIntent = null, ?string $charge = null, ?int $limit = null): array
    {
        $params = [];
        if ($paymentIntent !== null) {
            $params['payment_intent'] = $paymentIntent;
        }
        if ($charge !== null) {
            $params['charge'] = $charge;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('refunds')
            ->withData($params)
            ->get(fn (): array => $this->sdk()->refunds->all($params)->toArray());

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $entry) {
            if (is_array($entry)) {
                $items[] = StripeRefundData::from($entry);
            }
        }

        return $items;
    }
}
