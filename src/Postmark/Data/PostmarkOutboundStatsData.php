<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Spatie\LaravelData\Data;

/**
 * Aggregate stats for outbound traffic over a date range. The full stats
 * API has many endpoints (per tag, per platform, per client), all keyed
 * off the same date filter; we surface only the overview here. Everything
 * else is an `original`-shaped passthrough away.
 */
class PostmarkOutboundStatsData extends Data
{
    /**
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly int $Sent,
        public readonly int $Bounced,
        public readonly int $SMTPApiErrors,
        public readonly float $BounceRate,
        public readonly int $SpamComplaints,
        public readonly float $SpamComplaintsRate,
        public readonly int $Tracked,
        public readonly int $Opens,
        public readonly int $UniqueOpens,
        public readonly int $TotalClicks,
        public readonly int $UniqueLinksClicked,
        public readonly int $WithClientRecorded,
        public readonly int $WithPlatformRecorded,
        public readonly int $WithReadTimeRecorded,
        public readonly ?array $original = null,
    ) {}

    /**
     * @param  array<mixed>  $properties
     * @return array<mixed>
     */
    #[\Override]
    public static function prepareForPipeline(array $properties): array
    {
        $properties['original'] ??= $properties;

        return $properties;
    }
}
