<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Resources;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Integrations\Adapters\Zendesk\Data\ZendeskTicketData;
use Integrations\Adapters\Zendesk\Data\ZendeskUserData;
use Integrations\Adapters\Zendesk\Enums\ZendeskStatus;
use Integrations\Adapters\Zendesk\ZendeskResource;
use stdClass;
use Zendesk\API\Http;

use function Safe\parse_url;

class ZendeskTickets extends ZendeskResource
{
    public function get(int $ticketId): ?stdClass
    {
        return $this->executeWithErrorHandling(function () use ($ticketId): ?stdClass {
            $result = $this->integration
                ->to("tickets/{$ticketId}.json")
                ->get(function () use ($ticketId): ?stdClass {
                    $response = $this->sdk()->tickets()->find($ticketId);
                    $ticket = $response->ticket ?? null;

                    return $ticket instanceof stdClass ? $ticket : null;
                });

            return $result instanceof stdClass ? $result : null;
        });
    }

    public function close(int $ticketId): ?ZendeskTicketData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId): ?ZendeskTicketData {
            $result = $this->integration
                ->to("tickets/{$ticketId}.json")
                ->withData(['status' => ZendeskStatus::Solved->value])
                ->put(function () use ($ticketId): ?ZendeskTicketData {
                    $response = $this->sdk()->tickets()->update($ticketId, ['status' => ZendeskStatus::Solved->value]);

                    return $response instanceof stdClass ? $this->ticketDataFromResponse($response) : null;
                });

            return $result instanceof ZendeskTicketData ? $result : null;
        });
    }

    public function reopen(int $ticketId): ?ZendeskTicketData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId): ?ZendeskTicketData {
            $result = $this->integration
                ->to("tickets/{$ticketId}.json")
                ->withData(['status' => ZendeskStatus::Open->value])
                ->put(function () use ($ticketId): ?ZendeskTicketData {
                    $response = $this->sdk()->tickets()->update($ticketId, ['status' => ZendeskStatus::Open->value]);

                    return $response instanceof stdClass ? $this->ticketDataFromResponse($response) : null;
                });

            return $result instanceof ZendeskTicketData ? $result : null;
        });
    }

    /**
     * Iterate through all tickets and call the callback for each.
     *
     * @param  callable(object): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function list(callable $callback): void
    {
        $this->integration
            ->to('tickets.json')
            ->get(function () use ($callback): void {
                $iterator = $this->sdk()->tickets()->iterator();

                foreach ($iterator as $ticket) {
                    if (is_object($ticket)) {
                        $callback($ticket);
                    }
                }
            });
    }

    /**
     * Get tickets modified since a specific time (incremental sync).
     * Uses the Ticket Export API with sideloading for efficiency.
     *
     * @param  callable(ZendeskTicketData, ZendeskUserData|null): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function since(\DateTimeInterface $startTime, callable $callback): void
    {
        $timestamp = $startTime->getTimestamp();

        do {
            $start = Carbon::createFromTimestamp($timestamp);
            Log::info("ZendeskTickets: Fetching tickets since start_time={$start->toDateTimeString()}");

            $this->sdk()->setApiBasePath('api/v2/');

            $response = $this->integration
                ->to("incremental/tickets.json?start_time={$timestamp}")
                ->withData(['start_time' => $timestamp])
                ->get(fn () => $this->executeWithRetry(fn (): ?stdClass => Http::send(
                    $this->sdk(),
                    'incremental/tickets.json',
                    [
                        'queryParams' => [
                            'start_time' => $timestamp,
                            'include' => 'users',
                        ],
                    ]
                )));

            if (! $response instanceof stdClass) {
                Log::warning('ZendeskTickets: API returned null response, breaking loop');
                break;
            }

            /** @var list<object> $usersArray */
            $usersArray = is_array($response->users) ? $response->users : [];
            /** @var list<object> $ticketsArray */
            $ticketsArray = is_array($response->tickets) ? $response->tickets : [];

            Log::info('ZendeskTickets: API response received', [
                'tickets_count' => count($ticketsArray),
                'users_count' => count($usersArray),
                'has_next_page' => $response->next_page !== null,
            ]);

            if (count($ticketsArray) === 0) {
                break;
            }

            $users = self::buildUsersMap($usersArray);
            $this->processTicketObjects($ticketsArray, $users, $callback);

            $newTimestamp = is_string($response->next_page)
                ? $this->extractTimestampFromUrl($response->next_page)
                : null;

            if ($newTimestamp !== null && $newTimestamp > $timestamp) {
                $timestamp = $newTimestamp;
            } else {
                break;
            }
        } while (true);
    }

    /**
     * Get tickets with ID greater than the specified minimum.
     * Fetches recent tickets via Search API and filters locally by ID.
     *
     * @param  callable(ZendeskTicketData, ZendeskUserData|null): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function newerThan(int $minId, callable $callback): void
    {
        $page = 1;

        do {
            $this->sdk()->setApiBasePath('api/v2/');

            $response = $this->integration
                ->to("search.json?page={$page}")
                ->withData(['min_id' => $minId, 'page' => $page])
                ->get(fn () => $this->executeWithRetry(fn (): ?stdClass => Http::send(
                    $this->sdk(),
                    'search.json',
                    [
                        'queryParams' => [
                            'query' => 'type:ticket',
                            'sort_by' => 'created_at',
                            'sort_order' => 'desc',
                            'page' => $page,
                            'include' => 'tickets(users)',
                        ],
                    ]
                )));

            if (! $response instanceof stdClass) {
                break;
            }

            /** @var list<object> $resultsArray */
            $resultsArray = is_array($response->results) ? $response->results : [];

            /** @var list<object> $usersArray */
            $usersArray = property_exists($response, 'users') && is_array($response->users) ? $response->users : [];
            $users = self::buildUsersMap($usersArray);

            $reachedOlderTickets = $this->processTicketsNewerThan($resultsArray, $users, $minId, $callback);
            if ($reachedOlderTickets) {
                break;
            }

            $hasNextPage = $response->next_page !== null;
            $page++;
        } while ($hasNextPage);
    }

    /**
     * Process ticket objects, invoking the callback only for tickets newer than $minId.
     * Returns true if an older ticket was encountered (signalling pagination should stop).
     *
     * @param  list<object>  $ticketObjects
     * @param  Collection<int|string, ZendeskUserData>  $users
     * @param  callable(ZendeskTicketData, ZendeskUserData|null): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    private function processTicketsNewerThan(array $ticketObjects, Collection $users, int $minId, callable $callback): bool
    {
        $foundOlderTicket = false;

        foreach ($ticketObjects as $ticketObj) {
            try {
                $ticketArray = $this->objectToNormalizedArray($ticketObj);
                if ($ticketArray === null) {
                    continue;
                }
                $ticket = ZendeskTicketData::from($ticketArray);

                if ($ticket->id <= $minId) {
                    $foundOlderTicket = true;

                    continue;
                }

                $user = $users[$ticket->requester_id] ?? null;
                $callback($ticket, $user);
            } catch (\Throwable $e) {
                self::logTicketProcessingError($e, $ticketArray ?? null);
            }
        }

        return $foundOlderTicket;
    }

    private function ticketDataFromResponse(stdClass $response): ?ZendeskTicketData
    {
        $ticket = $response->ticket ?? null;
        if (! $ticket instanceof stdClass) {
            return null;
        }

        $ticketArray = $this->objectToNormalizedArray($ticket);
        if ($ticketArray === null) {
            return null;
        }

        return ZendeskTicketData::from($ticketArray);
    }

    /**
     * @param  list<object>  $ticketObjects
     * @param  Collection<int|string, ZendeskUserData>  $users
     * @param  callable(ZendeskTicketData, ZendeskUserData|null): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    private function processTicketObjects(array $ticketObjects, Collection $users, callable $callback): void
    {
        foreach ($ticketObjects as $ticketObj) {
            try {
                $ticketArray = $this->objectToNormalizedArray($ticketObj);
                if ($ticketArray === null) {
                    continue;
                }
                $ticket = ZendeskTicketData::from($ticketArray);
                $user = $users[$ticket->requester_id] ?? null;

                $callback($ticket, $user);
            } catch (\Throwable $e) {
                self::logTicketProcessingError($e, $ticketArray ?? null);
            }
        }
    }

    /**
     * @param  list<object>  $usersArray
     * @return Collection<int|string, ZendeskUserData>
     */
    private static function buildUsersMap(array $usersArray): Collection
    {
        return collect($usersArray)
            ->keyBy('id')
            ->map(fn (object $user): ZendeskUserData => ZendeskUserData::createFromZendeskResponse($user));
    }

    private function extractTimestampFromUrl(string $url): ?int
    {
        $query = parse_url($url)['query'] ?? '';
        $queryString = is_string($query) ? $query : '';
        parse_str($queryString, $queryParams);

        return array_key_exists('start_time', $queryParams) && is_numeric($queryParams['start_time'])
            ? (int) $queryParams['start_time']
            : null;
    }

    private static function logTicketProcessingError(\Throwable $e, mixed $ticketArray): void
    {
        $rawId = is_array($ticketArray) ? ($ticketArray['id'] ?? null) : null;
        $failedId = is_int($rawId) || is_string($rawId) ? (string) $rawId : 'unknown';
        Log::error("ZendeskTickets: Failed processing ticket {$failedId}: {$e->getMessage()}");
    }
}
