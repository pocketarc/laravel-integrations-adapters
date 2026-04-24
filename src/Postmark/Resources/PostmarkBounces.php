<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Resources;

use Integrations\Adapters\Postmark\Data\PostmarkBounceData;
use Integrations\Adapters\Postmark\Data\PostmarkBounceListResponse;
use Integrations\Adapters\Postmark\PostmarkResource;

/**
 * Bounces API: query past bounces, fetch raw dumps, reactivate suppressed
 * recipients. Postmark paginates `list()` by offset, so callers walk
 * through with successive `offset` + `count` calls. We don't auto-iterate
 * because the result set can be very large, and consumers usually filter
 * by `type` or `fromdate`/`todate` first.
 */
class PostmarkBounces extends PostmarkResource
{
    public function get(int $bounceId): ?PostmarkBounceData
    {
        return $this->executeWithErrorHandling(function () use ($bounceId): ?PostmarkBounceData {
            $result = $this->integration
                ->toAs("bounces/{$bounceId}", PostmarkBounceData::class)
                ->get(function () use ($bounceId): array {
                    return get_object_vars($this->sdk()->getBounce($bounceId));
                });

            return $result instanceof PostmarkBounceData ? $result : null;
        });
    }

    /**
     * @param  array{type?: string|null, inactive?: bool|null, emailFilter?: string|null, tag?: string|null, messageId?: int|null, fromdate?: string|null, todate?: string|null, messagestream?: string|null}  $filters
     */
    public function list(int $count = 100, int $offset = 0, array $filters = []): ?PostmarkBounceListResponse
    {
        return $this->executeWithErrorHandling(function () use ($count, $offset, $filters): ?PostmarkBounceListResponse {
            $result = $this->integration
                ->toAs('bounces', PostmarkBounceListResponse::class)
                ->withData(array_merge(['count' => $count, 'offset' => $offset], $filters))
                ->get(function () use ($count, $offset, $filters): array {
                    $list = $this->sdk()->getBounces(
                        count: $count,
                        offset: $offset,
                        type: $filters['type'] ?? null,
                        inactive: $filters['inactive'] ?? null,
                        emailFilter: $filters['emailFilter'] ?? null,
                        tag: $filters['tag'] ?? null,
                        messageID: $filters['messageId'] ?? null,
                        fromdate: $filters['fromdate'] ?? null,
                        todate: $filters['todate'] ?? null,
                        messagestream: $filters['messagestream'] ?? null,
                    );

                    $bounces = [];
                    foreach ($list->Bounces as $bounce) {
                        if (is_object($bounce)) {
                            $bounces[] = get_object_vars($bounce);
                        }
                    }

                    return [
                        'TotalCount' => $list->TotalCount,
                        'Bounces' => $bounces,
                    ];
                });

            return $result instanceof PostmarkBounceListResponse ? $result : null;
        });
    }

    /**
     * Re-enable sending to a previously bounced address. Only succeeds when
     * Postmark's Bounce record has `CanActivate=true` (e.g. soft bounces);
     * hard bounces and spam complaints typically can't be reactivated via
     * the API.
     */
    public function activate(int $bounceId): bool
    {
        return $this->executeWithErrorHandling(function () use ($bounceId): bool {
            $this->integration
                ->to("bounces/{$bounceId}/activate")
                ->put(function () use ($bounceId): bool {
                    $this->sdk()->activateBounce($bounceId);

                    return true;
                });

            return true;
        }, default: false);
    }

    /**
     * Raw SMTP/source dump for a bounce, as returned by Postmark. Useful for
     * forensic debugging when the abbreviated `Details` field doesn't pin
     * down the failure mode. Postmark may return null/empty for older
     * bounces past their retention window.
     */
    public function dump(int $bounceId): ?string
    {
        return $this->executeWithErrorHandling(function () use ($bounceId): ?string {
            $result = $this->integration
                ->to("bounces/{$bounceId}/dump")
                ->get(function () use ($bounceId): ?string {
                    $dump = $this->sdk()->getBounceDump($bounceId);
                    $body = $dump->Body ?? null;

                    return is_string($body) ? $body : null;
                });

            return is_string($result) ? $result : null;
        });
    }
}
