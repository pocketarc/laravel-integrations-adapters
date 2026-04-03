<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Integrations\Adapters\Concerns\RetriesRequests;
use Integrations\Adapters\Zendesk\Data\ZendeskCommentData;
use Integrations\Adapters\Zendesk\Data\ZendeskTicketData;
use Integrations\Adapters\Zendesk\Data\ZendeskUserData;
use Integrations\Adapters\Zendesk\Enums\ZendeskStatus;
use Integrations\Models\Integration;
use RuntimeException;
use stdClass;
use Zendesk\API\Exceptions\ApiResponseException;
use Zendesk\API\HttpClient as ZendeskAPI;

class ZendeskClient
{
    use RetriesRequests;

    private Integration $integration;

    private ZendeskAPI $sdk;

    private string $subdomain;

    private string $email;

    private string $token;

    public function __construct(Integration $integration, ?ZendeskAPI $sdk = null)
    {
        $credentials = $integration->credentials;
        $metadata = $integration->metadata;

        if (! $credentials instanceof ZendeskCredentials || ! $metadata instanceof ZendeskMetadata) {
            throw new RuntimeException('Invalid Zendesk integration: credentials or metadata type mismatch.');
        }

        $this->integration = $integration;
        $this->subdomain = $metadata->subdomain;
        $this->email = $credentials->email;
        $this->token = $credentials->token;

        if ($sdk !== null) {
            $this->sdk = $sdk;
        } else {
            $this->sdk = new ZendeskAPI($this->subdomain);
            $this->sdk->setAuth('basic', [
                'username' => $this->email,
                'token' => $this->token,
            ]);
        }
    }

    public function getSdkClient(): ZendeskAPI
    {
        return $this->sdk;
    }

    /**
     * Iterate through all tickets and call the callback for each.
     *
     * @param  callable(object): void  $callback
     */
    public function getTickets(callable $callback): void
    {
        $this->integration
            ->to('tickets.json')
            ->get(function () use ($callback): void {
                $iterator = $this->sdk->tickets()->iterator();

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
     */
    public function getTicketsSince(\DateTimeInterface $startTime, callable $callback): void
    {
        $timestamp = $startTime->getTimestamp();

        do {
            $start = Carbon::createFromTimestamp($timestamp);
            Log::info("ZendeskClient: Fetching tickets since start_time={$start->toDateTimeString()}");

            $this->sdk->setApiBasePath('api/v2/');

            $response = $this->integration
                ->to("incremental/tickets.json?start_time={$timestamp}")
                ->withData(['start_time' => $timestamp])
                ->get(fn () => $this->executeWithRetry(fn (): ?stdClass => \Zendesk\API\Http::send(
                    $this->sdk,
                    'incremental/tickets.json',
                    [
                        'queryParams' => [
                            'start_time' => $timestamp,
                            'include' => 'users',
                        ],
                    ]
                )));

            if (! $response instanceof stdClass) {
                Log::warning('ZendeskClient: API returned null response, breaking loop');
                break;
            }

            /** @var list<object> $usersArray */
            $usersArray = is_array($response->users) ? $response->users : [];
            /** @var list<object> $ticketsArray */
            $ticketsArray = is_array($response->tickets) ? $response->tickets : [];

            Log::info('ZendeskClient: API response received', [
                'tickets_count' => count($ticketsArray),
                'users_count' => count($usersArray),
                'has_next_page' => $response->next_page !== null,
            ]);

            if (count($ticketsArray) === 0) {
                break;
            }

            $users = collect($usersArray)
                ->keyBy('id')
                ->map(fn (object $user): ZendeskUserData => ZendeskUserData::createFromZendeskResponse($user));

            foreach ($ticketsArray as $ticketObj) {
                try {
                    $ticketArray = json_decode((string) json_encode($ticketObj), true);
                    if (! is_array($ticketArray)) {
                        continue;
                    }
                    $original = $ticketArray;
                    $ticketArray = $this->normalizeViaChannel($ticketArray);
                    $ticketArray['original'] = $original;
                    $ticket = ZendeskTicketData::from($ticketArray);
                    $user = $users[$ticket->requester_id] ?? null;

                    $callback($ticket, $user);
                } catch (\Throwable $e) {
                    $rawId = is_array($ticketArray ?? null) ? ($ticketArray['id'] ?? null) : null;
                    $failedId = is_int($rawId) || is_string($rawId) ? (string) $rawId : 'unknown';
                    Log::error("ZendeskClient: Failed processing ticket {$failedId}: {$e->getMessage()}");
                }
            }

            $nextPage = is_string($response->next_page);
            if ($nextPage) {
                $urlParts = parse_url($response->next_page);
                $queryString = is_array($urlParts) && isset($urlParts['query']) ? $urlParts['query'] : '';
                parse_str($queryString, $queryParams);
                $newTimestamp = isset($queryParams['start_time']) && is_numeric($queryParams['start_time']) ? (int) $queryParams['start_time'] : $timestamp;
                if ($newTimestamp > $timestamp) {
                    $timestamp = $newTimestamp;
                } else {
                    $nextPage = false;
                }
            }
        } while ($nextPage);
    }

    /**
     * Get tickets with ID greater than the specified minimum.
     * Fetches recent tickets via Search API and filters locally by ID.
     *
     * @param  callable(ZendeskTicketData, ZendeskUserData|null): void  $callback
     */
    public function getTicketsNewerThan(int $minId, callable $callback): void
    {
        $page = 1;
        $foundOlderTicket = false;

        do {
            $this->sdk->setApiBasePath('api/v2/');

            $response = $this->integration
                ->to("search.json?page={$page}")
                ->withData(['min_id' => $minId, 'page' => $page])
                ->get(fn () => $this->executeWithRetry(fn (): ?stdClass => \Zendesk\API\Http::send(
                    $this->sdk,
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
            $usersArray = isset($response->users) && is_array($response->users) ? $response->users : [];
            $users = collect($usersArray)
                ->keyBy('id')
                ->map(fn (object $user): ZendeskUserData => ZendeskUserData::createFromZendeskResponse($user));

            foreach ($resultsArray as $ticketObj) {
                try {
                    $ticketArray = json_decode((string) json_encode($ticketObj), true);
                    if (! is_array($ticketArray)) {
                        continue;
                    }
                    $original = $ticketArray;
                    $ticketArray = $this->normalizeViaChannel($ticketArray);
                    $ticketArray['original'] = $original;
                    $ticket = ZendeskTicketData::from($ticketArray);

                    if ($ticket->id <= $minId) {
                        $foundOlderTicket = true;

                        continue;
                    }

                    $user = $users[$ticket->requester_id] ?? null;

                    $callback($ticket, $user);
                } catch (\Throwable $e) {
                    $rawId = is_array($ticketArray ?? null) ? ($ticketArray['id'] ?? null) : null;
                    $failedId = is_int($rawId) || is_string($rawId) ? (string) $rawId : 'unknown';
                    Log::error("ZendeskClient: Failed processing ticket {$failedId}: {$e->getMessage()}");
                }
            }

            if ($foundOlderTicket) {
                break;
            }

            $hasNextPage = $response->next_page !== null;
            $page++;
        } while ($hasNextPage);
    }

    /**
     * Iterate through all users and call the callback for each.
     *
     * @param  (callable(ZendeskUserData): void)|null  $callback
     * @return Collection<int, ZendeskUserData>
     */
    public function getUsers(?callable $callback = null): Collection
    {
        /** @var Collection<int, ZendeskUserData> $users */
        $users = new Collection;

        $this->integration
            ->to('users.json')
            ->get(function () use ($callback, &$users): void {
                $iterator = $this->sdk->users()->iterator();

                foreach ($iterator as $user) {
                    if (! is_object($user)) {
                        continue;
                    }
                    $data = ZendeskUserData::createFromZendeskResponse($user);
                    if ($callback !== null) {
                        $callback($data);
                    }

                    $users->push($data);
                }
            },
            );

        return $users;
    }

    /**
     * Iterate through all comments for a ticket.
     *
     * @param  callable(ZendeskCommentData): void  $callback
     */
    public function getTicketComments(int $ticketId, callable $callback): void
    {
        /** @var array<string, mixed> $params */
        $params = ['page[size]' => 100];

        do {
            $commentsResponse = $this->integration
                ->to("tickets/{$ticketId}/comments.json")
                ->withData($params)
                ->get(fn () => $this->executeWithRetry(
                    fn () => $this->sdk->tickets($ticketId)->comments()->findAll($params)
                ));

            if (! $commentsResponse instanceof stdClass || ! isset($commentsResponse->comments) || ! is_array($commentsResponse->comments)) {
                return;
            }

            foreach ($commentsResponse->comments as $commentObj) {
                $commentArray = json_decode((string) json_encode($commentObj), true);
                if (! is_array($commentArray)) {
                    continue;
                }
                $original = $commentArray;
                $commentArray = $this->normalizeViaChannel($commentArray);
                $commentArray['original'] = $original;
                $comment = ZendeskCommentData::from($commentArray);
                $callback($comment);
            }

            $cursor = $this->getNextCursor($commentsResponse);
            if ($cursor !== null) {
                $params['page[after]'] = $cursor;
            }
        } while ($cursor !== null);
    }

    public function downloadAttachment(string $url): ?string
    {
        return $this->executeWithErrorHandling(function () use ($url): ?string {
            $result = $this->integration
                ->to($url)
                ->get(function () use ($url): ?string {
                    $response = Http::timeout(120)->get($url);

                    return $response->successful() ? $response->body() : null;
                });

            return is_string($result) ? $result : null;
        });
    }

    /**
     * Get a fresh content_url for an attachment by fetching comments from its ticket.
     */
    public function getFreshAttachmentUrl(int $ticketId, int $attachmentId): ?string
    {
        return $this->executeWithErrorHandling(function () use ($ticketId, $attachmentId): ?string {
            /** @var array<string, mixed> $params */
            $params = ['page[size]' => 100];

            do {
                $commentsResponse = $this->integration
                    ->to("tickets/{$ticketId}/comments.json")
                    ->withData($params)
                    ->get(fn () => $this->executeWithRetry(
                        fn () => $this->sdk->tickets($ticketId)->comments()->findAll($params)
                    ));

                if (! $commentsResponse instanceof stdClass || ! isset($commentsResponse->comments) || ! is_array($commentsResponse->comments)) {
                    return null;
                }

                foreach ($commentsResponse->comments as $comment) {
                    if (! $comment instanceof stdClass) {
                        continue;
                    }
                    $attachments = $comment->attachments ?? [];
                    if (! is_array($attachments)) {
                        continue;
                    }
                    foreach ($attachments as $attachment) {
                        if (! $attachment instanceof stdClass) {
                            continue;
                        }
                        if (isset($attachment->id) && $attachment->id === $attachmentId) {
                            return isset($attachment->content_url) && is_string($attachment->content_url) ? $attachment->content_url : null;
                        }
                    }
                }

                $cursor = $this->getNextCursor($commentsResponse);
                if ($cursor !== null) {
                    $params['page[after]'] = $cursor;
                }
            } while ($cursor !== null);

            return null;
        });
    }

    public function getTicket(int $ticketId): ?stdClass
    {
        return $this->executeWithErrorHandling(function () use ($ticketId): ?stdClass {
            $result = $this->integration
                ->to("tickets/{$ticketId}.json")
                ->get(function () use ($ticketId): ?stdClass {
                    $response = $this->sdk->tickets()->find($ticketId);
                    $ticket = $response->ticket ?? null;

                    return $ticket instanceof stdClass ? $ticket : null;
                });

            return $result instanceof stdClass ? $result : null;
        });
    }

    public function getUser(int $userId): ?stdClass
    {
        return $this->executeWithErrorHandling(function () use ($userId): ?stdClass {
            $result = $this->integration
                ->to("users/{$userId}.json")
                ->get(function () use ($userId): ?stdClass {
                    $response = $this->sdk->users()->find($userId);
                    $user = $response->user ?? null;

                    return $user instanceof stdClass ? $user : null;
                });

            return $result instanceof stdClass ? $result : null;
        });
    }

    public function closeTicket(int $ticketId): ?ZendeskTicketData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId): ?ZendeskTicketData {
            $result = $this->integration
                ->to("tickets/{$ticketId}.json")
                ->withData(['status' => ZendeskStatus::Solved->value])
                ->put(function () use ($ticketId): ?ZendeskTicketData {
                    $response = $this->sdk->tickets()->update($ticketId, ['status' => ZendeskStatus::Solved->value]);

                    return $response instanceof stdClass ? $this->ticketDataFromResponse($response) : null;
                });

            return $result instanceof ZendeskTicketData ? $result : null;
        });
    }

    public function reopenTicket(int $ticketId): ?ZendeskTicketData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId): ?ZendeskTicketData {
            $result = $this->integration
                ->to("tickets/{$ticketId}.json")
                ->withData(['status' => ZendeskStatus::Open->value])
                ->put(function () use ($ticketId): ?ZendeskTicketData {
                    $response = $this->sdk->tickets()->update($ticketId, ['status' => ZendeskStatus::Open->value]);

                    return $response instanceof stdClass ? $this->ticketDataFromResponse($response) : null;
                });

            return $result instanceof ZendeskTicketData ? $result : null;
        });
    }

    public function addComment(int $ticketId, string $comment): ?ZendeskCommentData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId, $comment): ?ZendeskCommentData {
            $result = $this->integration
                ->to("tickets/{$ticketId}.json")
                ->withData(['comment' => $comment])
                ->put(function () use ($ticketId, $comment): ?ZendeskCommentData {
                    $response = $this->sdk->tickets()->update($ticketId, [
                        'comment' => [
                            'body' => $comment,
                            'public' => true,
                        ],
                    ]);

                    return $response instanceof stdClass ? $this->commentDataFromResponse($response) : null;
                });

            return $result instanceof ZendeskCommentData ? $result : null;
        });
    }

    public function addInternalNote(int $ticketId, string $note): ?ZendeskCommentData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId, $note): ?ZendeskCommentData {
            $result = $this->integration
                ->to("tickets/{$ticketId}.json")
                ->withData(['note' => $note])
                ->put(function () use ($ticketId, $note): ?ZendeskCommentData {
                    $response = $this->sdk->tickets()->update($ticketId, [
                        'comment' => [
                            'body' => $note,
                            'public' => false,
                        ],
                    ]);

                    return $response instanceof stdClass ? $this->commentDataFromResponse($response) : null;
                });

            return $result instanceof ZendeskCommentData ? $result : null;
        });
    }

    private function commentDataFromResponse(stdClass $response): ?ZendeskCommentData
    {
        $audit = $response->audit ?? null;
        if (! $audit instanceof stdClass) {
            return null;
        }

        $events = $audit->events ?? [];
        if (! is_array($events)) {
            return null;
        }

        $auditId = $audit->id ?? null;
        if (! is_int($auditId)) {
            return null;
        }

        foreach ($events as $event) {
            if (! $event instanceof stdClass) {
                continue;
            }
            if (($event->type ?? null) !== 'Comment') {
                continue;
            }

            $commentArray = json_decode((string) json_encode($event), true);
            if (! is_array($commentArray)) {
                continue;
            }

            $commentArray['audit_id'] = $auditId;
            $original = $commentArray;
            $commentArray = $this->normalizeViaChannel($commentArray);
            $commentArray['original'] = $original;

            return ZendeskCommentData::from($commentArray);
        }

        return null;
    }

    private function ticketDataFromResponse(stdClass $response): ?ZendeskTicketData
    {
        $ticket = $response->ticket ?? null;
        if (! $ticket instanceof stdClass) {
            return null;
        }

        $ticketArray = json_decode((string) json_encode($ticket), true);
        if (! is_array($ticketArray)) {
            return null;
        }

        $original = $ticketArray;
        $ticketArray = $this->normalizeViaChannel($ticketArray);
        $ticketArray['original'] = $original;

        return ZendeskTicketData::from($ticketArray);
    }

    /**
     * @return array{int, string}|null
     */
    protected function getRetryDelay(\Throwable $e, int $attempt): ?array
    {
        if (! $e instanceof ApiResponseException) {
            return null;
        }

        $statusCode = $this->getStatusCodeFromException($e);

        if ($statusCode === 429) {
            $delay = $this->getRetryAfterDelay($e) ?? 30;

            return [$delay, 'Rate limited (429)'];
        }

        if ($statusCode !== null && $statusCode >= 500 && $statusCode < 600) {
            return [$attempt, "Server error ({$statusCode})"];
        }

        return null;
    }

    private function getStatusCodeFromException(ApiResponseException $e): ?int
    {
        $previous = $e->getPrevious();
        if ($previous instanceof GuzzleRequestException && $previous->getResponse() !== null) {
            return $previous->getResponse()->getStatusCode();
        }

        return null;
    }

    private function getRetryAfterDelay(ApiResponseException $e): ?int
    {
        $previous = $e->getPrevious();
        if (! $previous instanceof GuzzleRequestException || $previous->getResponse() === null) {
            return null;
        }

        $retryAfter = $previous->getResponse()->getHeaderLine('Retry-After');
        if ($retryAfter !== '' && is_numeric($retryAfter)) {
            return min((int) $retryAfter, 60);
        }

        return null;
    }

    private function getNextCursor(mixed $response): ?string
    {
        if (! $response instanceof stdClass) {
            return null;
        }

        $meta = $response->meta ?? null;
        if (! $meta instanceof stdClass) {
            return null;
        }

        $hasMore = $meta->has_more ?? false;
        if ($hasMore !== true) {
            return null;
        }

        $cursor = $meta->after_cursor ?? null;

        return is_string($cursor) ? $cursor : null;
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>
     */
    private function normalizeViaChannel(array $data): array
    {
        if (isset($data['via']) && is_array($data['via']) && isset($data['via']['channel']) && is_int($data['via']['channel'])) {
            $data['via']['channel'] = (string) $data['via']['channel'];
        }

        return $data;
    }
}
