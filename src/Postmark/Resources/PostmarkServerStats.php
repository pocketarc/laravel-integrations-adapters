<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Resources;

use Integrations\Adapters\Postmark\Data\PostmarkOutboundStatsData;
use Integrations\Adapters\Postmark\PostmarkResource;

/**
 * Aggregate stats for outbound traffic on this server. v1 only surfaces
 * the overview endpoint, since that's what dashboards usually want; the
 * per-tag, per-platform, and per-client breakdowns are still accessible
 * directly through the SDK if a consumer needs them.
 *
 * Dates use Postmark's `YYYY-MM-DD` format and are inclusive on both ends.
 */
class PostmarkServerStats extends PostmarkResource
{
    public function overview(?string $fromDate = null, ?string $toDate = null, ?string $tag = null, ?string $messageStream = null): ?PostmarkOutboundStatsData
    {
        $this->assertYmdDate($fromDate, 'fromDate');
        $this->assertYmdDate($toDate, 'toDate');

        return $this->executeWithErrorHandling(function () use ($fromDate, $toDate, $tag, $messageStream): PostmarkOutboundStatsData {
            return $this->integration
                ->at('stats/outbound')
                ->as(PostmarkOutboundStatsData::class)
                ->withData(array_filter([
                    'fromdate' => $fromDate,
                    'todate' => $toDate,
                    'tag' => $tag,
                    'messagestream' => $messageStream,
                ], static fn (?string $value): bool => $value !== null))
                ->get(function () use ($fromDate, $toDate, $tag, $messageStream): array {
                    return get_object_vars($this->sdk()->getOutboundOverviewStatistics(
                        tag: $tag,
                        fromdate: $fromDate,
                        todate: $toDate,
                        messagestream: $messageStream,
                    ));
                });
        });
    }
}
