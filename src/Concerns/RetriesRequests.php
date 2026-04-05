<?php

declare(strict_types=1);

namespace Integrations\Adapters\Concerns;

use Illuminate\Support\Facades\Log;
use Integrations\Exceptions\RetriesExhaustedException;

trait RetriesRequests
{
    /**
     * Determine whether a failed request should be retried.
     *
     * Return [delay_seconds, reason_string] to retry, or null to not retry.
     *
     * @return array{int, string}|null
     */
    abstract protected function getRetryDelay(\Throwable $e, int $attempt): ?array;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     *
     * @param-immediately-invoked-callable $callback
     *
     * @return T
     *
     * @throws \Throwable
     */
    protected function executeWithRetry(callable $callback, int $maxRetries = 3): mixed
    {
        $lastException = null;
        $retriesMade = 0;
        $clientName = class_basename(static::class);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastException = $e;
                $retryInfo = $this->getRetryDelay($e, $attempt);

                if ($retryInfo !== null && $attempt < $maxRetries) {
                    [$delay, $reason] = $retryInfo;
                    $retriesMade++;
                    Log::warning("{$clientName}: {$reason}, retry {$attempt}/{$maxRetries} in {$delay}s");
                    sleep($delay);

                    continue;
                }

                if ($retriesMade > 0) {
                    throw new RetriesExhaustedException($retriesMade, $e);
                }
                throw $e;
            }
        }

        throw $lastException ?? new \RuntimeException('Retry logic exhausted without result');
    }

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
