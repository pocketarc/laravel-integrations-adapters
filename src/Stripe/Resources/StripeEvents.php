<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\Data\StripeEventData;
use Integrations\Adapters\Stripe\StripeResource;

class StripeEvents extends StripeResource
{
    public function retrieve(string $id): StripeEventData
    {
        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("events/{$id}")
            ->get(fn (): array => $this->sdk()->events->retrieve($id)->toArray());

        return self::hydrate($response);
    }

    /**
     * @param  list<string>|null  $types  Filter by event type (e.g. `['payment_intent.succeeded']`).
     * @return list<StripeEventData>
     */
    public function list(?array $types = null, ?int $limit = null): array
    {
        $params = [];
        if ($types !== null) {
            $params['types'] = $types;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('events')
            ->withData($params)
            ->get(fn (): array => $this->sdk()->events->all($params)->toArray());

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $entry) {
            if (is_array($entry)) {
                $items[] = self::hydrate($entry);
            }
        }

        return $items;
    }

    /**
     * Stripe nests the triggering object under `data.object`; StripeEventData
     * wants that object flattened into its own field for direct access.
     *
     * @param  array<mixed, mixed>  $payload
     */
    private static function hydrate(array $payload): StripeEventData
    {
        $dataObject = [];
        $data = $payload['data'] ?? null;
        if (is_array($data) && is_array($data['object'] ?? null)) {
            $dataObject = $data['object'];
        }

        return StripeEventData::from([
            'id' => $payload['id'] ?? '',
            'type' => $payload['type'] ?? '',
            'data_object' => $dataObject,
            'created' => $payload['created'] ?? null,
            'api_version' => $payload['api_version'] ?? null,
            'livemode' => (bool) ($payload['livemode'] ?? false),
            'pending_webhooks' => $payload['pending_webhooks'] ?? null,
        ]);
    }
}
