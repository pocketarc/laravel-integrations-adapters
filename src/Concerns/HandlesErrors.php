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
