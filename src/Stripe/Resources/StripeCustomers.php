<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\StripeResource;
use Integrations\RequestContext;
use Stripe\Collection;
use Stripe\Customer;

class StripeCustomers extends StripeResource
{
    /**
     * @param  array<string, string>|null  $metadata
     */
    public function create(
        ?string $email = null,
        ?string $name = null,
        ?string $description = null,
        ?string $phone = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
    ): Customer {
        $params = [];
        if ($email !== null) {
            $params['email'] = $email;
        }
        if ($name !== null) {
            $params['name'] = $name;
        }
        if ($description !== null) {
            $params['description'] = $description;
        }
        if ($phone !== null) {
            $params['phone'] = $phone;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        $response = $this->integration
            ->at('customers')
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(function (RequestContext $ctx) use ($params): Customer {
                $customer = $this->sdk()->customers->create(
                    $params,
                    ['idempotency_key' => $ctx->idempotencyKey],
                );
                $this->reportStripeMetadata($ctx);

                return $customer;
            });

        return $this->expectInstance($response, Customer::class);
    }

    public function retrieve(string $id): Customer
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("customers/{$id}")
            ->get(function (RequestContext $ctx) use ($id): Customer {
                $customer = $this->sdk()->customers->retrieve($id);
                $this->reportStripeMetadata($ctx);

                return $customer;
            });

        return $this->expectInstance($response, Customer::class);
    }

    /**
     * @param  array<string, string>|null  $metadata
     */
    public function update(
        string $id,
        ?string $email = null,
        ?string $name = null,
        ?string $description = null,
        ?string $phone = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
    ): Customer {
        $this->assertId($id);

        $params = [];
        if ($email !== null) {
            $params['email'] = $email;
        }
        if ($name !== null) {
            $params['name'] = $name;
        }
        if ($description !== null) {
            $params['description'] = $description;
        }
        if ($phone !== null) {
            $params['phone'] = $phone;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        $response = $this->integration
            ->at("customers/{$id}")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(function (RequestContext $ctx) use ($id, $params): Customer {
                $customer = $this->sdk()->customers->update(
                    $id,
                    $params,
                    ['idempotency_key' => $ctx->idempotencyKey],
                );
                $this->reportStripeMetadata($ctx);

                return $customer;
            });

        return $this->expectInstance($response, Customer::class);
    }

    public function delete(string $id): Customer
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("customers/{$id}")
            ->delete(function (RequestContext $ctx) use ($id): Customer {
                $customer = $this->sdk()->customers->delete($id);
                $this->reportStripeMetadata($ctx);

                return $customer;
            });

        return $this->expectInstance($response, Customer::class);
    }

    /**
     * @return Collection<Customer>
     */
    public function list(?string $email = null, ?int $limit = null): Collection
    {
        $params = [];
        if ($email !== null) {
            $params['email'] = $email;
        }
        if ($limit !== null) {
            $this->assertPositive($limit, 'limit');
            $params['limit'] = $limit;
        }

        $response = $this->integration
            ->at('customers')
            ->withData($params)
            ->get(function (RequestContext $ctx) use ($params): Collection {
                $list = $this->sdk()->customers->all($params);
                $this->reportStripeMetadata($ctx);

                return $list;
            });

        return $this->expectInstance($response, Collection::class);
    }
}
