<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\StripeResource;
use Integrations\RequestContext;
use Stripe\Collection;
use Stripe\WebhookEndpoint;

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
        ?string $idempotencyKey = null,
    ): WebhookEndpoint {
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

        $response = $this->integration
            ->at('webhook_endpoints')
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(function (RequestContext $ctx) use ($params): WebhookEndpoint {
                $endpoint = $this->sdk()->webhookEndpoints->create(
                    $params,
                    ['idempotency_key' => $ctx->idempotencyKey],
                );
                $this->reportStripeMetadata($ctx);

                return $endpoint;
            });

        return $this->expectInstance($response, WebhookEndpoint::class);
    }

    public function retrieve(string $id): WebhookEndpoint
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("webhook_endpoints/{$id}")
            ->get(function (RequestContext $ctx) use ($id): WebhookEndpoint {
                $endpoint = $this->sdk()->webhookEndpoints->retrieve($id);
                $this->reportStripeMetadata($ctx);

                return $endpoint;
            });

        return $this->expectInstance($response, WebhookEndpoint::class);
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
        ?string $idempotencyKey = null,
    ): WebhookEndpoint {
        $this->assertId($id);

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

        $response = $this->integration
            ->at("webhook_endpoints/{$id}")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(function (RequestContext $ctx) use ($id, $params): WebhookEndpoint {
                $endpoint = $this->sdk()->webhookEndpoints->update(
                    $id,
                    $params,
                    ['idempotency_key' => $ctx->idempotencyKey],
                );
                $this->reportStripeMetadata($ctx);

                return $endpoint;
            });

        return $this->expectInstance($response, WebhookEndpoint::class);
    }

    public function delete(string $id): WebhookEndpoint
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("webhook_endpoints/{$id}")
            ->delete(function (RequestContext $ctx) use ($id): WebhookEndpoint {
                $endpoint = $this->sdk()->webhookEndpoints->delete($id);
                $this->reportStripeMetadata($ctx);

                return $endpoint;
            });

        return $this->expectInstance($response, WebhookEndpoint::class);
    }

    /**
     * @return Collection<WebhookEndpoint>
     */
    public function list(?int $limit = null): Collection
    {
        $params = [];
        if ($limit !== null) {
            $this->assertPositive($limit, 'limit');
            $params['limit'] = $limit;
        }

        $response = $this->integration
            ->at('webhook_endpoints')
            ->withData($params)
            ->get(function (RequestContext $ctx) use ($params): Collection {
                $list = $this->sdk()->webhookEndpoints->all($params);
                $this->reportStripeMetadata($ctx);

                return $list;
            });

        return $this->expectInstance($response, Collection::class);
    }
}
