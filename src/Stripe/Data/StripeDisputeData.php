<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Data;

use Spatie\LaravelData\Data;

class StripeDisputeData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $status,
        public readonly string $reason,
        public readonly ?string $payment_intent = null,
        public readonly ?string $charge = null,
        public readonly bool $is_charge_refundable = false,
        /** @var array<string, mixed>|null */
        public readonly ?array $evidence = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $evidence_details = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $metadata = null,
        public readonly ?int $created = null,
    ) {}
}
