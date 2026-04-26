<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\StripeResource;
use Integrations\RequestContext;
use Stripe\Collection;
use Stripe\Dispute;

class StripeDisputes extends StripeResource
{
    public function retrieve(string $id): Dispute
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("disputes/{$id}")
            ->get(function (RequestContext $ctx) use ($id): Dispute {
                $dispute = $this->sdk()->disputes->retrieve($id);
                $this->reportStripeMetadata($ctx);

                return $dispute;
            });

        return $this->expectInstance($response, Dispute::class);
    }

    /**
     * Update metadata or submit a drafted dispute. Evidence submission has
     * its own typed entry point in a follow-up; Stripe's evidence shape is
     * large and nested and deserves a dedicated Data class rather than a
     * loosely-typed array passthrough here.
     *
     * @param  array<string, string>|null  $metadata
     */
    public function update(
        string $id,
        ?bool $submit = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
    ): Dispute {
        $this->assertId($id);

        $params = [];
        if ($submit !== null) {
            $params['submit'] = $submit;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        $response = $this->integration
            ->at("disputes/{$id}")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(function (RequestContext $ctx) use ($id, $params): Dispute {
                $dispute = $this->sdk()->disputes->update(
                    $id,
                    $params,
                    ['idempotency_key' => $ctx->idempotencyKey],
                );
                $this->reportStripeMetadata($ctx);

                return $dispute;
            });

        return $this->expectInstance($response, Dispute::class);
    }

    public function close(string $id, ?string $idempotencyKey = null): Dispute
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("disputes/{$id}/close")
            ->withIdempotencyKey($idempotencyKey)
            ->post(function (RequestContext $ctx) use ($id): Dispute {
                $dispute = $this->sdk()->disputes->close(
                    $id,
                    null,
                    ['idempotency_key' => $ctx->idempotencyKey],
                );
                $this->reportStripeMetadata($ctx);

                return $dispute;
            });

        return $this->expectInstance($response, Dispute::class);
    }

    /**
     * @return Collection<Dispute>
     */
    public function list(?string $charge = null, ?string $paymentIntent = null, ?int $limit = null): Collection
    {
        $params = [];
        if ($charge !== null) {
            $this->assertId($charge);
            $params['charge'] = $charge;
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
            ->at('disputes')
            ->withData($params)
            ->get(function (RequestContext $ctx) use ($params): Collection {
                $list = $this->sdk()->disputes->all($params);
                $this->reportStripeMetadata($ctx);

                return $list;
            });

        return $this->expectInstance($response, Collection::class);
    }
}
