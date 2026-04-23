<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Data;

use Spatie\LaravelData\Data;

class StripeCustomerData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $email = null,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $phone = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $address = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $metadata = null,
        public readonly ?int $created = null,
    ) {}
}
