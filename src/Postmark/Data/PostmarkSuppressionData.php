<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Data;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * One entry from the per-stream suppression list. `Origin` is "Recipient",
 * "Customer", or "Admin" (Postmark, you/the API caller, or Postmark staff
 * respectively). `SuppressionReason` enumerates Postmark's documented
 * reasons (HardBounce, SpamComplaint, ManualSuppression, etc.).
 */
class PostmarkSuppressionData extends Data
{
    /**
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly string $EmailAddress,
        public readonly string $SuppressionReason,
        public readonly string $Origin,
        public readonly Carbon $CreatedAt,
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
