<?php

declare(strict_types=1);

namespace Integrations\Adapters\Concerns;

use Illuminate\Support\Facades\Log;

trait HandlesErrors
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     *
     * @param-immediately-invoked-callable $callback
     *
     * @param  T  $default
     * @return T
     */
    protected function executeWithErrorHandling(callable $callback, mixed $default = null): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            // Idempotency conflicts are part of the documented adapter
            // contract: callers catch them to skip duplicate work. Rethrow
            // so the swallow-and-return-default path below can't turn them
            // into the sentinel value indistinguishably from a generic
            // failure. Matched by FQCN string instead of a typed catch so
            // the trait keeps compiling against laravel-integrations 2.2.x
            // (which doesn't ship the class yet) until the consumer bumps
            // to 2.3.x as the baseline.
            if ($e::class === 'Integrations\\Exceptions\\IdempotencyConflict') {
                throw $e;
            }

            if (config('app.debug') === true) {
                throw $e;
            }

            $clientName = class_basename(static::class);
            Log::error("{$clientName}: ".$e->getMessage(), ['exception' => $e]);
            report($e);

            return $default;
        }
    }
}
