<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\Data\StripeWebhookEndpointData;
use Integrations\Adapters\Stripe\StripeResource;

class StripeWebhookEndpoints extends StripeResource
{
    /**
     * @param  list<string>  $enabledEvents  Event types to receive (e.g. `['payment_intent.succeeded']`).
     * @param  array<string, string>|null  $metadata
     */
    public function create(
        string $url,
        array $enabledEvents,
        ?string $description = null,
        ?array $metadata = null,
    ): StripeWebhookEndpointData {
        $params = [
            'url' => $url,
            'enabled_events' => $enabledEvents,
        ];
        if ($description !== null) {
            $params['description'] = $description;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('webhook_endpoints')
            ->withData($params)
            ->post(fn (): array => $this->sdk()->webhookEndpoints->create($params)->toArray());

        return StripeWebhookEndpointData::from($response);
    }

    public function retrieve(string $id): StripeWebhookEndpointData
    {
        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("webhook_endpoints/{$id}")
            ->get(fn (): array => $this->sdk()->webhookEndpoints->retrieve($id)->toArray());

        return StripeWebhookEndpointData::from($response);
    }

    /**
     * @param  list<string>|null  $enabledEvents
     * @param  array<string, string>|null  $metadata
     */
    public function update(
        string $id,
        ?string $url = null,
        ?array $enabledEvents = null,
        ?string $description = null,
        ?bool $disabled = null,
        ?array $metadata = null,
    ): StripeWebhookEndpointData {
        $params = [];
        if ($url !== null) {
            $params['url'] = $url;
        }
        if ($enabledEvents !== null) {
            $params['enabled_events'] = $enabledEvents;
        }
        if ($description !== null) {
            $params['description'] = $description;
        }
        if ($disabled !== null) {
            $params['disabled'] = $disabled;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("webhook_endpoints/{$id}")
            ->withData($params)
            ->post(fn (): array => $this->sdk()->webhookEndpoints->update($id, $params)->toArray());

        return StripeWebhookEndpointData::from($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $id): array
    {
        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("webhook_endpoints/{$id}")
            ->delete(fn (): array => $this->sdk()->webhookEndpoints->delete($id)->toArray());

        return $response;
    }

    /**
     * @return list<StripeWebhookEndpointData>
     */
    public function list(?int $limit = null): array
    {
        $params = [];
        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('webhook_endpoints')
            ->withData($params)
            ->get(fn (): array => $this->sdk()->webhookEndpoints->all($params)->toArray());

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $entry) {
            if (is_array($entry)) {
                $items[] = StripeWebhookEndpointData::from($entry);
            }
        }

        return $items;
    }
}
