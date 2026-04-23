<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Data;

use Spatie\LaravelData\Data;

class StripeChargeData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $status,
        public readonly bool $paid,
        public readonly bool $refunded,
        public readonly ?string $payment_intent = null,
        public readonly ?string $customer = null,
        public readonly ?string $receipt_url = null,
        public readonly ?string $receipt_email = null,
        public readonly ?int $amount_refunded = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $metadata = null,
        public readonly ?int $created = null,
    ) {}
}
