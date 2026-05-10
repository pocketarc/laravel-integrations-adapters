<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk;

use Illuminate\Support\Carbon;
use Integrations\Models\Integration;

/**
 * Per-sync state for ZendeskProvider::syncIncremental(). Encapsulates the
 * counters, the earliest-failure tracker, and the per-item cursor checkpoint
 * write so the provider's iterator callback stays short.
 */
class ZendeskSyncState
{
    private int $successCount = 0;

    private int $failureCount = 0;

    private ?Carbon $earliestFailureAt = null;

    private ?Carbon $lastCheckpoint = null;

    public function __construct(
        private readonly Integration $integration,
    ) {}

    public function successCount(): int
    {
        return $this->successCount;
    }

    public function failureCount(): int
    {
        return $this->failureCount;
    }

    public function earliestFailureAt(): ?Carbon
    {
        return $this->earliestFailureAt;
    }

    /**
     * Record a successful sync of an item and, when safe, persist its
     * `updated_at` to the integration's `sync_cursor`. SIGKILL or job-timeout
     * mid-sync then leaves the cursor at the last successful item rather than
     * back at the original $since. Skipped once a failure exists, so we never
     * checkpoint past a known-bad item.
     */
    public function recordSuccess(?Carbon $updatedAt): void
    {
        $this->successCount++;

        if ($updatedAt === null) {
            return;
        }
        if ($this->earliestFailureAt !== null) {
            return;
        }
        if ($this->lastCheckpoint !== null && ! $updatedAt->isAfter($this->lastCheckpoint)) {
            return;
        }
        $this->lastCheckpoint = $updatedAt;
        $this->integration->updateSyncCursor($updatedAt->toIso8601String());
    }

    public function recordFailure(?Carbon $updatedAt): void
    {
        $this->failureCount++;
        if ($updatedAt === null) {
            return;
        }
        $this->earliestFailureAt = $this->earliestFailureAt === null
            ? $updatedAt
            : $this->earliestFailureAt->min($updatedAt);
    }
}
