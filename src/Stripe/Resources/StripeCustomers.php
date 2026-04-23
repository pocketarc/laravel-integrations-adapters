<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\Data\StripeCustomerData;
use Integrations\Adapters\Stripe\StripeResource;

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
    ): StripeCustomerData {
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

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('customers')
            ->withData($params)
            ->post(fn (): array => $this->sdk()->customers->create($params)->toArray());

        return StripeCustomerData::from($response);
    }

    public function retrieve(string $id): StripeCustomerData
    {
        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("customers/{$id}")
            ->get(fn (): array => $this->sdk()->customers->retrieve($id)->toArray());

        return StripeCustomerData::from($response);
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
    ): StripeCustomerData {
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

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("customers/{$id}")
            ->withData($params)
            ->post(fn (): array => $this->sdk()->customers->update($id, $params)->toArray());

        return StripeCustomerData::from($response);
    }

    /**
     * @return array<string, mixed> Raw deletion receipt (e.g. `['id' => ..., 'deleted' => true]`).
     */
    public function delete(string $id): array
    {
        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to("customers/{$id}")
            ->delete(fn (): array => $this->sdk()->customers->delete($id)->toArray());

        return $response;
    }

    /**
     * @return list<StripeCustomerData>
     */
    public function list(?string $email = null, ?int $limit = null): array
    {
        $params = [];
        if ($email !== null) {
            $params['email'] = $email;
        }
        if ($limit !== null) {
            $params['limit'] = $limit;
        }

        /** @var array<string, mixed> $response */
        $response = $this->integration
            ->to('customers')
            ->withData($params)
            ->get(fn (): array => $this->sdk()->customers->all($params)->toArray());

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $entry) {
            if (is_array($entry)) {
                $items[] = StripeCustomerData::from($entry);
            }
        }

        return $items;
    }
}
