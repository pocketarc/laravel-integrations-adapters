<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\StripeResource;
use Stripe\Collection;
use Stripe\Dispute;

class StripeDisputes extends StripeResource
{
    public function retrieve(string $id): Dispute
    {
        $this->assertId($id);

        $response = $this->integration
            ->to("disputes/{$id}")
            ->get(fn (): Dispute => $this->sdk()->disputes->retrieve($id));

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
            ->to("disputes/{$id}")
            ->withData($params)
            ->post(fn (): Dispute => $this->sdk()->disputes->update($id, $params));

        return $this->expectInstance($response, Dispute::class);
    }

    public function close(string $id): Dispute
    {
        $this->assertId($id);

        $response = $this->integration
            ->to("disputes/{$id}/close")
            ->post(fn (): Dispute => $this->sdk()->disputes->close($id));

        return $this->expectInstance($response, Dispute::class);
    }

    /**
     * @return Collection<Dispute>
     */
    public function list(?string $charge = null, ?string $paymentIntent = null, ?int $limit = null): Collection
    {
        $params = [];
        if ($charge !== null) {
            $params['charge'] = $charge;
        }
        if ($paymentIntent !== null) {
            $params['payment_intent'] = $paymentIntent;
        }
        if ($limit !== null) {
            $this->assertPositive($limit, 'limit');
            $params['limit'] = $limit;
        }

        $response = $this->integration
            ->to('disputes')
            ->withData($params)
            ->get(fn (): Collection => $this->sdk()->disputes->all($params));

        return $this->expectInstance($response, Collection::class);
    }
}
