<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Resources;

use Integrations\Adapters\Postmark\Data\PostmarkSuppressionListResponse;
use Integrations\Adapters\Postmark\PostmarkResource;
use Postmark\Models\Suppressions\SuppressionChangeRequest;

/**
 * Per-stream suppression list management. Each Postmark message stream
 * has its own list, so suppressing `user@example.com` on the
 * transactional `outbound` stream does not stop them receiving from a
 * `broadcasts` stream (and vice versa). The default stream comes from
 * the integration's metadata; callers can override per-call.
 */
class PostmarkSuppressions extends PostmarkResource
{
    /**
     * @param  array{suppressionReason?: string|null, origin?: string|null, fromDate?: string|null, toDate?: string|null, emailAddress?: string|null}  $filters
     */
    public function list(?string $messageStream = null, array $filters = []): ?PostmarkSuppressionListResponse
    {
        $stream = $messageStream ?? $this->client->defaultMessageStream();

        return $this->executeWithErrorHandling(function () use ($stream, $filters): ?PostmarkSuppressionListResponse {
            $result = $this->integration
                ->toAs("message-streams/{$stream}/suppressions/dump", PostmarkSuppressionListResponse::class)
                ->withData($filters)
                ->get(function () use ($stream, $filters): array {
                    $list = $this->sdk()->getSuppressions(
                        messageStream: $stream,
                        suppressionReason: $filters['suppressionReason'] ?? null,
                        origin: $filters['origin'] ?? null,
                        fromDate: $filters['fromDate'] ?? null,
                        toDate: $filters['toDate'] ?? null,
                        emailAddress: $filters['emailAddress'] ?? null,
                    );

                    $suppressions = [];
                    foreach ($list->getSuppressions() as $suppression) {
                        if (is_object($suppression)) {
                            $suppressions[] = get_object_vars($suppression);
                        }
                    }

                    return [
                        'Suppressions' => $suppressions,
                    ];
                });

            return $result instanceof PostmarkSuppressionListResponse ? $result : null;
        });
    }

    /**
     * Suppress one or more recipients on a stream. Returns true on success;
     * the per-recipient outcome is in the API response (FailureMessage,
     * Status), but most callers don't need that breakdown because failures
     * throw via the standard error pipeline.
     *
     * @param  list<string>  $emailAddresses
     */
    public function create(array $emailAddresses, ?string $messageStream = null): bool
    {
        if ($emailAddresses === []) {
            return true;
        }

        $stream = $messageStream ?? $this->client->defaultMessageStream();

        return $this->executeWithErrorHandling(function () use ($emailAddresses, $stream): bool {
            $changes = array_map(fn (string $email): SuppressionChangeRequest => new SuppressionChangeRequest($email), $emailAddresses);

            $this->integration
                ->to("message-streams/{$stream}/suppressions")
                ->withData(['Suppressions' => array_map(fn (string $email): array => ['EmailAddress' => $email], $emailAddresses)])
                ->post(function () use ($changes, $stream): bool {
                    $this->sdk()->createSuppressions($changes, $stream);

                    return true;
                });

            return true;
        }, default: false);
    }

    /**
     * Reactivate previously suppressed recipients. Postmark only allows
     * lifting `Customer`-origin `ManualSuppression` and `Recipient`-origin
     * `HardBounce` entries; other suppressions (spam complaints, admin
     * holds) silently no-op.
     *
     * @param  list<string>  $emailAddresses
     */
    public function delete(array $emailAddresses, ?string $messageStream = null): bool
    {
        if ($emailAddresses === []) {
            return true;
        }

        $stream = $messageStream ?? $this->client->defaultMessageStream();

        return $this->executeWithErrorHandling(function () use ($emailAddresses, $stream): bool {
            $changes = array_map(fn (string $email): SuppressionChangeRequest => new SuppressionChangeRequest($email), $emailAddresses);

            $this->integration
                ->to("message-streams/{$stream}/suppressions/delete")
                ->withData(['Suppressions' => array_map(fn (string $email): array => ['EmailAddress' => $email], $emailAddresses)])
                ->post(function () use ($changes, $stream): bool {
                    $this->sdk()->deleteSuppressions($changes, $stream);

                    return true;
                });

            return true;
        }, default: false);
    }
}
