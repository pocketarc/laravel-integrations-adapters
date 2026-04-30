<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe;

use Spatie\LaravelData\Data;

/**
 * Per-account metadata for a Stripe integration. Deliberately empty today:
 * Stripe's own dashboard is the source of truth for prices, products, etc.,
 * and the API key is scoped to a single Stripe account. Consumers that
 * need extra config (e.g. a default price ID) can still pass it through
 * via the free-form metadata column when calling the install command.
 */
class StripeMetadata extends Data {}
