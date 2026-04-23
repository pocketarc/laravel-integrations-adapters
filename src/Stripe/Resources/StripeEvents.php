<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\StripeResource;
use Stripe\Collection;
use Stripe\Event;

class StripeEvents extends StripeResource
{
    public function retrieve(string $id): Event
    {
        $this->assertId($id);

        $response = $this->integration
            ->to("events/{$id}")
            ->get(fn (): Event => $this->sdk()->events->retrieve($id));

        return $this->expectInstance($response, Event::class);
    }

    /**
     * @param  list<string>|null  $types  Filter by event type (e.g. `['payment_intent.succeeded']`).
     * @return Collection<Event>
     */
    public function list(?array $types = null, ?int $limit = null): Collection
    {
        $params = [];
        if ($types !== null) {
            $params['types'] = $types;
        }
        if ($limit !== null) {
            $this->assertPositive($limit, 'limit');
            $params['limit'] = $limit;
        }

        $response = $this->integration
            ->to('events')
            ->withData($params)
            ->get(fn (): Collection => $this->sdk()->events->all($params));

        return $this->expectInstance($response, Collection::class);
    }
}
