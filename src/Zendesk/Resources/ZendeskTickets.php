<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Resources;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Integrations\Adapters\Zendesk\Data\ZendeskIncrementalTicketResponse;
use Integrations\Adapters\Zendesk\Data\ZendeskSearchResponse;
use Integrations\Adapters\Zendesk\Data\ZendeskTicketData;
use Integrations\Adapters\Zendesk\Data\ZendeskUserData;
use Integrations\Adapters\Zendesk\Enums\ZendeskStatus;
use Integrations\Adapters\Zendesk\ZendeskResource;
use stdClass;
use Zendesk\API\Http;

class ZendeskTickets extends ZendeskResource
{
    public function get(int $ticketId): ?ZendeskTicketData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId): ?ZendeskTicketData {
            $result = $this->integration
                ->toAs("tickets/{$ticketId}.json", ZendeskTicketData::class)
                ->get(function () use ($ticketId): ?stdClass {
                    $response = $this->sdk()->tickets()->find($ticketId);
                    $ticket = $response->ticket ?? null;

                    return $ticket instanceof stdClass ? $ticket : null;
                });

            return $result instanceof ZendeskTicketData ? $result : null;
        });
    }

    public function close(int $ticketId): ?ZendeskTicketData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId): ?ZendeskTicketData {
            $result = $this->integration
                ->toAs("tickets/{$ticketId}.json", ZendeskTicketData::class)
                ->withData(['status' => ZendeskStatus::Solved->value])
                ->put(function () use ($ticketId): ?stdClass {
                    $response = $this->sdk()->tickets()->update($ticketId, ['status' => ZendeskStatus::Solved->value]);
                    $ticket = $response->ticket ?? null;

                    return $ticket instanceof stdClass ? $ticket : null;
                });

            return $result instanceof ZendeskTicketData ? $result : null;
        });
    }

    public function reopen(int $ticketId): ?ZendeskTicketData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId): ?ZendeskTicketData {
            $result = $this->integration
                ->toAs("tickets/{$ticketId}.json", ZendeskTicketData::class)
                ->withData(['status' => ZendeskStatus::Open->value])
                ->put(function () use ($ticketId): ?stdClass {
                    $response = $this->sdk()->tickets()->update($ticketId, ['status' => ZendeskStatus::Open->value]);
                    $ticket = $response->ticket ?? null;

                    return $ticket instanceof stdClass ? $ticket : null;
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
        $originalBasePath = $this->sdk()->getApiBasePath();

        try {
            do {
                $this->sdk()->setApiBasePath('api/v2/');

                $response = $this->integration
                    ->toAs("incremental/tickets.json?start_time={$timestamp}", ZendeskIncrementalTicketResponse::class)
                    ->withData(['start_time' => $timestamp])
                    ->get(fn () => Http::send(
                        $this->sdk(),
                        'incremental/tickets.json',
                        [
                            'queryParams' => [
                                'start_time' => $timestamp,
                                'include' => 'users',
                            ],
                        ]
                    ));

                if (! $response instanceof ZendeskIncrementalTicketResponse || $response->tickets->isEmpty()) {
                    break;
                }

                $this->dispatchTickets($response->tickets, $response->users->keyBy('id'), $callback);

                $newTimestamp = $response->nextTimestamp();

                if ($newTimestamp !== null && $newTimestamp > $timestamp) {
                    $timestamp = $newTimestamp;
                } else {
                    break;
                }
            } while (true);
        } finally {
            $this->sdk()->setApiBasePath($originalBasePath);
        }
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
        $originalBasePath = $this->sdk()->getApiBasePath();

        try {
            do {
                $this->sdk()->setApiBasePath('api/v2/');

                $response = $this->integration
                    ->toAs("search.json?page={$page}", ZendeskSearchResponse::class)
                    ->withData(['min_id' => $minId, 'page' => $page])
                    ->get(fn () => Http::send(
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
                    ));

                if (! $response instanceof ZendeskSearchResponse) {
                    break;
                }

                $users = $response->users->keyBy('id');

                if ($this->dispatchTicketsNewerThan($response->results, $users, $minId, $callback)) {
                    break;
                }

                $hasNextPage = $response->next_page !== null;
                $page++;
            } while ($hasNextPage);
        } finally {
            $this->sdk()->setApiBasePath($originalBasePath);
        }
    }

    /**
     * @param  Collection<int, ZendeskTicketData>  $tickets
     * @param  Collection<int|string, ZendeskUserData>  $users
     * @param  callable(ZendeskTicketData, ZendeskUserData|null): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    private function dispatchTickets(Collection $tickets, Collection $users, callable $callback): void
    {
        foreach ($tickets as $ticket) {
            try {
                $user = $users[$ticket->requester_id] ?? null;
                $callback($ticket, $user instanceof ZendeskUserData ? $user : null);
            } catch (\Throwable $e) {
                Log::error("ZendeskTickets: Failed processing ticket {$ticket->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Dispatch tickets newer than $minId. Returns true if an older ticket was encountered.
     *
     * @param  Collection<int, ZendeskTicketData>  $tickets
     * @param  Collection<int|string, ZendeskUserData>  $users
     * @param  callable(ZendeskTicketData, ZendeskUserData|null): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    private function dispatchTicketsNewerThan(Collection $tickets, Collection $users, int $minId, callable $callback): bool
    {
        $reachedOlderTickets = false;

        foreach ($tickets as $ticket) {
            try {
                if ($ticket->id <= $minId) {
                    $reachedOlderTickets = true;

                    continue;
                }

                $user = $users[$ticket->requester_id] ?? null;
                $callback($ticket, $user instanceof ZendeskUserData ? $user : null);
            } catch (\Throwable $e) {
                Log::error("ZendeskTickets: Failed processing ticket {$ticket->id}: {$e->getMessage()}");
            }
        }

        return $reachedOlderTickets;
    }
}
