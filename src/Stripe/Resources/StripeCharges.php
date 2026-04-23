<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\Data\StripeChargeData;
use Integrations\Adapters\Stripe\StripeResource;

class StripeCharges extends StripeResource
{
    public function retrieve(string $id): StripeChargeData
    {
        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("charges/{$id}")
            ->get(fn (): array => $this->sdk()->charges->retrieve($id)->toArray());

        return StripeChargeData::from($response);
    }

    public function capture(string $id, ?int $amount = null, ?string $receiptEmail = null): StripeChargeData
    {
        $params = [];
        if ($amount !== null) {
            $params['amount'] = $amount;
        }
        if ($receiptEmail !== null) {
            $params['receipt_email'] = $receiptEmail;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("charges/{$id}/capture")
            ->withData($params)
            ->post(fn (): array => $this->sdk()->charges->capture($id, $params)->toArray());

        return StripeChargeData::from($response);
    }

    /**
     * @return list<StripeChargeData>
     */
    public function list(?string $customer = null, ?string $paymentIntent = null, ?int $limit = null): array
    {
        $params = [];
        if ($customer !== null) {
            $params['customer'] = $customer;
        }
        if ($paymentIntent !== null) {
            $params['payment_intent'] = $paymentIntent;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('charges')
            ->withData($params)
            ->get(fn (): array => $this->sdk()->charges->all($params)->toArray());

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $entry) {
            if (is_array($entry)) {
                $items[] = StripeChargeData::from($entry);
            }
        }

        return $items;
    }
}
