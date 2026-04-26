<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Resources;

use Illuminate\Support\Facades\Log;
use Integrations\Adapters\Postmark\Data\PostmarkSuppressionListResponse;
use Integrations\Adapters\Postmark\PostmarkResource;
use Postmark\Models\Suppressions\PostmarkSuppressionRequestResult;
use Postmark\Models\Suppressions\PostmarkSuppressionResultList;
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

        return $this->executeWithErrorHandling(function () use ($stream, $filters): PostmarkSuppressionListResponse {
            return $this->integration
                ->at("message-streams/{$stream}/suppressions/dump")
                ->as(PostmarkSuppressionListResponse::class)
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
        });
    }

    /**
     * Suppress one or more recipients on a stream. Returns true only if
     * Postmark accepts every address. The Suppressions API returns a
     * per-recipient `Status`, so a 200 from the transport layer does not
     * by itself mean every address landed: an address with a malformed
     * format or Admin-origin override comes back with Status="Failed"
     * alongside a reason in `Message`. We treat any non-Suppressed entry
     * as a failure for the whole batch and return false; the details end
     * up in the logs via `Log::warning`.
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

            $response = $this->integration
                ->at("message-streams/{$stream}/suppressions")
                ->withData(['Suppressions' => array_map(fn (string $email): array => ['EmailAddress' => $email], $emailAddresses)])
                ->post(function () use ($changes, $stream): PostmarkSuppressionResultList {
                    return $this->sdk()->createSuppressions($changes, $stream);
                });

            return $this->allRecipientsSucceeded($response, expectedStatus: 'Suppressed', action: 'create');
        }, default: false);
    }

    /**
     * Reactivate previously suppressed recipients. Postmark only allows
     * lifting `Customer`-origin `ManualSuppression` and `Recipient`-origin
     * `HardBounce` entries; other suppressions (spam complaints, admin
     * holds) silently no-op.
     *
     * Same per-recipient result handling as `create()`: we only return
     * true when every address comes back with Status="Deleted".
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

            $response = $this->integration
                ->at("message-streams/{$stream}/suppressions/delete")
                ->withData(['Suppressions' => array_map(fn (string $email): array => ['EmailAddress' => $email], $emailAddresses)])
                ->post(function () use ($changes, $stream): PostmarkSuppressionResultList {
                    return $this->sdk()->deleteSuppressions($changes, $stream);
                });

            return $this->allRecipientsSucceeded($response, expectedStatus: 'Deleted', action: 'delete');
        }, default: false);
    }

    private function allRecipientsSucceeded(mixed $response, string $expectedStatus, string $action): bool
    {
        if (! $response instanceof PostmarkSuppressionResultList) {
            return false;
        }

        $failures = [];
        foreach ($response->getSuppressions() as $result) {
            if (! $result instanceof PostmarkSuppressionRequestResult) {
                continue;
            }

            if ($result->Status === $expectedStatus) {
                continue;
            }

            $failures[] = [
                'email' => $result->EmailAddress,
                'status' => $result->Status,
                'message' => $result->Message,
            ];
        }

        if ($failures !== []) {
            Log::warning("PostmarkSuppressions: {$action} rejected one or more recipients", [
                'integration_id' => $this->integration->id,
                'failures' => $failures,
            ]);

            return false;
        }

        return true;
    }
}
