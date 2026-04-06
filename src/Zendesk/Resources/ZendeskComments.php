<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Resources;

use Integrations\Adapters\Zendesk\Data\ZendeskCommentData;
use Integrations\Adapters\Zendesk\Data\ZendeskCommentPageResponse;
use Integrations\Adapters\Zendesk\ZendeskResource;
use stdClass;

use function Safe\json_decode;
use function Safe\json_encode;

class ZendeskComments extends ZendeskResource
{
    /**
     * Iterate through all comments for a ticket.
     *
     * @param  callable(ZendeskCommentData): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function list(int $ticketId, callable $callback): void
    {
        /** @var array<string, mixed> $params */
        $params = ['page[size]' => 100];

        do {
            $response = $this->integration
                ->toAs("tickets/{$ticketId}/comments.json", ZendeskCommentPageResponse::class)
                ->withData($params)
                ->get(fn () => $this->sdk()->tickets($ticketId)->comments()->findAll($params));

            if (! $response instanceof ZendeskCommentPageResponse) {
                return;
            }

            foreach ($response->comments as $comment) {
                $callback($comment);
            }

            $cursor = $response->meta->has_more ? $response->meta->after_cursor : null;
            if ($cursor !== null) {
                $params['page[after]'] = $cursor;
            }
        } while ($cursor !== null);
    }

    public function add(int $ticketId, string $comment): ?ZendeskCommentData
    {
        return $this->executeWithErrorHandling(function () use ($ticketId, $comment): ?ZendeskCommentData {
            $result = $this->integration
                ->to("tickets/{$ticketId}.json")
                ->withData(['comment' => $comment])
                ->put(function () use ($ticketId, $comment): ?ZendeskCommentData {
                    $response = $this->sdk()->tickets()->update($ticketId, [
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
                    $response = $this->sdk()->tickets()->update($ticketId, [
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

            $commentArray = json_decode(json_encode($event), true);
            if (! is_array($commentArray)) {
                continue;
            }

            $commentArray['audit_id'] = $auditId;
            $commentArray['created_at'] ??= $audit->created_at ?? null;
            $commentArray['metadata'] ??= is_object($audit->metadata ?? null)
                ? json_decode(json_encode($audit->metadata), true)
                : [];
            $commentArray['via'] ??= is_object($audit->via ?? null)
                ? json_decode(json_encode($audit->via), true)
                : ['channel' => 'api', 'source' => []];

            return ZendeskCommentData::from($commentArray);
        }

        return null;
    }
}
