<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\StripeResource;
use Integrations\RequestContext;
use Stripe\Collection;
use Stripe\PaymentIntent;

/**
 * Wrapper around Stripe's PaymentIntent service. Exposes typed named arguments
 * for the common fields. When you need a Stripe option we haven't exposed yet,
 * extend this class or open a PR against the adapter; the deliberate
 * alternative is a `$params` passthrough that breaks type inference and makes
 * the adapter a thin rename over `\Stripe\StripeClient`.
 */
class StripePaymentIntents extends StripeResource
{
    /**
     * An auto-generated idempotency key covers core retries inside one call,
     * not re-issues from a queued job. Pass a stable key (e.g. derived from
     * the originating domain event) when you need cross-invocation safety.
     *
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
        ?string $idempotencyKey = null,
    ): PaymentIntent {
        $this->assertPositive($amount, 'amount');

        $params = [
            'amount' => $amount,
            'currency' => $currency,
            'automatic_payment_methods' => ['enabled' => $automaticPaymentMethods],
        ];

        if ($customer !== null) {
            $this->assertId($customer);
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

        $response = $this->integration
            ->at('payment_intents')
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->create(
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    public function retrieve(string $id): PaymentIntent
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("payment_intents/{$id}")
            ->get(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->retrieve($id),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    /**
     * @param  array<string, string>|null  $metadata
     */
    public function update(
        string $id,
        ?string $description = null,
        ?string $receiptEmail = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
    ): PaymentIntent {
        $this->assertId($id);

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

        $response = $this->integration
            ->at("payment_intents/{$id}")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->update(
                    $id,
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    public function confirm(string $id, ?string $paymentMethod = null, ?string $idempotencyKey = null): PaymentIntent
    {
        $this->assertId($id);

        $params = [];
        if ($paymentMethod !== null) {
            $this->assertId($paymentMethod);
            $params['payment_method'] = $paymentMethod;
        }

        $response = $this->integration
            ->at("payment_intents/{$id}/confirm")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->confirm(
                    $id,
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    public function capture(string $id, ?int $amountToCapture = null, ?string $idempotencyKey = null): PaymentIntent
    {
        $this->assertId($id);

        $params = [];
        if ($amountToCapture !== null) {
            $this->assertPositive($amountToCapture, 'amountToCapture');
            $params['amount_to_capture'] = $amountToCapture;
        }

        $response = $this->integration
            ->at("payment_intents/{$id}/capture")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->capture(
                    $id,
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    public function cancel(string $id, ?string $cancellationReason = null, ?string $idempotencyKey = null): PaymentIntent
    {
        $this->assertId($id);

        $params = [];
        if ($cancellationReason !== null) {
            $params['cancellation_reason'] = $cancellationReason;
        }

        $response = $this->integration
            ->at("payment_intents/{$id}/cancel")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->cancel(
                    $id,
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    /**
     * @return Collection<PaymentIntent>
     */
    public function list(?string $customer = null, ?int $limit = null): Collection
    {
        $params = [];
        if ($customer !== null) {
            $this->assertId($customer);
            $params['customer'] = $customer;
        }
        if ($limit !== null) {
            $this->assertPositive($limit, 'limit');
            $params['limit'] = $limit;
        }

        $response = $this->integration
            ->at('payment_intents')
            ->withData($params)
            ->get(fn (RequestContext $ctx): Collection => $this->callStripe(
                $ctx,
                fn (): Collection => $this->sdk()->paymentIntents->all($params),
            ));

        return $this->expectInstance($response, Collection::class);
    }
}
