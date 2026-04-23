<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Data;

use Spatie\LaravelData\Data;

/**
 * Typed view over Stripe's PaymentIntent resource. Fields are the subset
 * consumers actually read; Stripe returns many more, and Spatie Data will
 * ignore them on `from()`.
 */
class StripePaymentIntentData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?string $client_secret = null,
        public readonly ?string $customer = null,
        public readonly ?string $receipt_email = null,
        public readonly ?string $description = null,
        public readonly ?int $amount_received = null,
        public readonly ?int $amount_capturable = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $metadata = null,
        public readonly ?int $created = null,
    ) {}
}
