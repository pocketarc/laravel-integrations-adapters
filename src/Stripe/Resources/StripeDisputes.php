<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\Data\StripeDisputeData;
use Integrations\Adapters\Stripe\StripeResource;

class StripeDisputes extends StripeResource
{
    public function retrieve(string $id): StripeDisputeData
    {
        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("disputes/{$id}")
            ->get(fn (): array => $this->sdk()->disputes->retrieve($id)->toArray());

        return StripeDisputeData::from($response);
    }

    /**
     * Update metadata or submit a drafted dispute. Evidence submission has
     * its own typed entry point in a follow-up — Stripe's evidence shape is
     * large and nested and deserves a dedicated Data class rather than a
     * loosely-typed array passthrough here.
     *
     * @param  array<string, string>|null  $metadata
     */
    public function update(
        string $id,
        ?bool $submit = null,
        ?array $metadata = null,
    ): StripeDisputeData {
        $params = [];
        if ($submit !== null) {
            $params['submit'] = $submit;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("disputes/{$id}")
            ->withData($params)
            ->post(fn (): array => $this->sdk()->disputes->update($id, $params)->toArray());

        return StripeDisputeData::from($response);
    }

    public function close(string $id): StripeDisputeData
    {
        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("disputes/{$id}/close")
            ->post(fn (): array => $this->sdk()->disputes->close($id)->toArray());

        return StripeDisputeData::from($response);
    }

    /**
     * @return list<StripeDisputeData>
     */
    public function list(?string $charge = null, ?string $paymentIntent = null, ?int $limit = null): array
    {
        $params = [];
        if ($charge !== null) {
            $params['charge'] = $charge;
        }
        if ($paymentIntent !== null) {
            $params['payment_intent'] = $paymentIntent;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('disputes')
            ->withData($params)
            ->get(fn (): array => $this->sdk()->disputes->all($params)->toArray());

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $entry) {
            if (is_array($entry)) {
                $items[] = StripeDisputeData::from($entry);
            }
        }

        return $items;
    }
}
