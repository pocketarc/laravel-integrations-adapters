<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\Data\StripeRefundData;
use Integrations\Adapters\Stripe\StripeResource;

class StripeRefunds extends StripeResource
{
    /**
     * Refund against a charge or a payment intent. Exactly one of `paymentIntent`
     * or `charge` must be provided.
     *
     * @param  array<string, string>|null  $metadata
     */
    public function create(
        ?string $paymentIntent = null,
        ?string $charge = null,
        ?int $amount = null,
        ?string $reason = null,
        ?array $metadata = null,
    ): StripeRefundData {
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

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('refunds')
            ->withData($params)
            ->post(fn (): array => $this->sdk()->refunds->create($params)->toArray());

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
