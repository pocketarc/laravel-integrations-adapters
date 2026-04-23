<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe;

use Integrations\Models\Integration;
use InvalidArgumentException;
use Stripe\StripeClient as StripeSdkClient;
use UnexpectedValueException;

abstract class StripeResource
{
    public function __construct(
        protected readonly Integration $integration,
        protected readonly StripeClient $client,
    ) {}

    protected function sdk(): StripeSdkClient
    {
        return $this->client->getSdkClient();
    }

    /**
     * Empty ids route to the Stripe list endpoint (e.g. `refunds/` instead of
     * `refunds/re_123`), silently returning the wrong shape. Fail fast.
     */
    protected function assertId(string $id): void
    {
        if ($id === '') {
            throw new InvalidArgumentException('Stripe resource id cannot be empty.');
        }
    }

    protected function assertPositive(int $value, string $parameter): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Stripe %s must be positive, got %d.',
                $parameter,
                $value,
            ));
        }
    }

    /**
     * Narrow the `mixed` return from `Integration::request()` back to the
     * Stripe type the closure produced.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function expectInstance(mixed $value, string $class): object
    {
        if (! $value instanceof $class) {
            throw new UnexpectedValueException(sprintf(
                'Expected instance of %s, got %s.',
                $class,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
