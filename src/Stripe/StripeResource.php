<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe;

use Integrations\Models\Integration;
use Integrations\RequestContext;
use InvalidArgumentException;
use Stripe\StripeClient as StripeSdkClient;
use Stripe\Util\CaseInsensitiveArray;
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
     * Pull what we can from Stripe's last response (request ID, rate-limit
     * info when present) and feed it back to core via the RequestContext.
     * Stripe sets `Request-Id` on every response — that's the value support
     * tickets ask for. Rate-limit headers are only emitted on 429s, so most
     * calls report just the request ID.
     *
     * Adapter resource methods should call this right after the SDK call
     * returns inside the closure passed to `Integration::request()`.
     */
    protected function reportStripeMetadata(RequestContext $ctx): void
    {
        $last = $this->sdk()->getLastResponse();
        if ($last === null) {
            return;
        }

        $headers = $last->headers;

        $ctx->reportResponseMetadata(
            providerRequestId: $this->stringHeader($headers, 'Request-Id'),
        );
    }

    /**
     * Stripe's CaseInsensitiveArray supports normal array access by any
     * casing, but we still need a defensive cast to string so PHPStan can
     * see a non-mixed return.
     *
     * @param  CaseInsensitiveArray<string, mixed>|array<string, mixed>  $headers
     */
    private function stringHeader(mixed $headers, string $name): ?string
    {
        if (! is_array($headers) && ! ($headers instanceof \ArrayAccess)) {
            return null;
        }

        $value = $headers[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
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
