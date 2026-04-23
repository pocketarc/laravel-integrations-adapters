<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\Data\StripePaymentIntentData;
use Integrations\Adapters\Stripe\StripeResource;

/**
 * Wrapper around Stripe's PaymentIntent service. Exposes typed named arguments
 * for the common fields. When you need a Stripe option we haven't exposed yet,
 * extend this class or open a PR against the adapter — the deliberate
 * alternative is a `$params` passthrough that breaks type inference and makes
 * the adapter a thin rename over `\Stripe\StripeClient`.
 */
class StripePaymentIntents extends StripeResource
{
    /**
     * @param  array<string, string>|null  $metadata
     */
    public function create(
        int $amount,
        string $currency,
        ?string $customer = null,
        ?string $receiptEmail = null,
        ?string $description = null,
        ?array $metadata = null,
        bool $automaticPaymentMethods = true,
    ): StripePaymentIntentData {
        $params = [
            'amount' => $amount,
            'currency' => $currency,
            'automatic_payment_methods' => ['enabled' => $automaticPaymentMethods],
        ];

        if ($customer !== null) {
            $params['customer'] = $customer;
        }
        if ($receiptEmail !== null) {
            $params['receipt_email'] = $receiptEmail;
        }
        if ($description !== null) {
            $params['description'] = $description;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('payment_intents')
            ->withData($params)
            ->post(fn (): array => $this->sdk()->paymentIntents->create($params)->toArray());

        return StripePaymentIntentData::from($response);
    }

    public function retrieve(string $id): StripePaymentIntentData
    {
        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("payment_intents/{$id}")
            ->get(fn (): array => $this->sdk()->paymentIntents->retrieve($id)->toArray());

        return StripePaymentIntentData::from($response);
    }

    /**
     * @param  array<string, string>|null  $metadata
     */
    public function update(
        string $id,
        ?string $description = null,
        ?string $receiptEmail = null,
        ?array $metadata = null,
    ): StripePaymentIntentData {
        $params = [];
        if ($description !== null) {
            $params['description'] = $description;
        }
        if ($receiptEmail !== null) {
            $params['receipt_email'] = $receiptEmail;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("payment_intents/{$id}")
            ->withData($params)
            ->post(fn (): array => $this->sdk()->paymentIntents->update($id, $params)->toArray());

        return StripePaymentIntentData::from($response);
    }

    public function confirm(string $id, ?string $paymentMethod = null): StripePaymentIntentData
    {
        $params = [];
        if ($paymentMethod !== null) {
            $params['payment_method'] = $paymentMethod;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("payment_intents/{$id}/confirm")
            ->withData($params)
            ->post(fn (): array => $this->sdk()->paymentIntents->confirm($id, $params)->toArray());

        return StripePaymentIntentData::from($response);
    }

    public function capture(string $id, ?int $amountToCapture = null): StripePaymentIntentData
    {
        $params = [];
        if ($amountToCapture !== null) {
            $params['amount_to_capture'] = $amountToCapture;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("payment_intents/{$id}/capture")
            ->withData($params)
            ->post(fn (): array => $this->sdk()->paymentIntents->capture($id, $params)->toArray());

        return StripePaymentIntentData::from($response);
    }

    public function cancel(string $id, ?string $cancellationReason = null): StripePaymentIntentData
    {
        $params = [];
        if ($cancellationReason !== null) {
            $params['cancellation_reason'] = $cancellationReason;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("payment_intents/{$id}/cancel")
            ->withData($params)
            ->post(fn (): array => $this->sdk()->paymentIntents->cancel($id, $params)->toArray());

        return StripePaymentIntentData::from($response);
    }

    /**
     * @return list<StripePaymentIntentData>
     */
    public function list(?string $customer = null, ?int $limit = null): array
    {
        $params = [];
        if ($customer !== null) {
            $params['customer'] = $customer;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('payment_intents')
            ->withData($params)
            ->get(fn (): array => $this->sdk()->paymentIntents->all($params)->toArray());

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $entry) {
            if (is_array($entry)) {
                $items[] = StripePaymentIntentData::from($entry);
            }
        }

        return $items;
    }
}
