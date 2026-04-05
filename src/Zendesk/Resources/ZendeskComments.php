<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Resources;

use Integrations\Adapters\Zendesk\Data\ZendeskCommentData;
use Integrations\Adapters\Zendesk\ZendeskResource;
use stdClass;

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
            $commentsResponse = $this->integration
                ->to("tickets/{$ticketId}/comments.json")
                ->withData($params)
                ->get(fn () => $this->executeWithRetry(
                    fn () => $this->sdk()->tickets($ticketId)->comments()->findAll($params)
                ));

            if (! $commentsResponse instanceof stdClass || ! property_exists($commentsResponse, 'comments') || ! is_array($commentsResponse->comments)) {
                return;
            }

            foreach ($commentsResponse->comments as $commentObj) {
                if (! is_object($commentObj)) {
                    continue;
                }
                $commentArray = $this->objectToNormalizedArray($commentObj);
                if ($commentArray === null) {
                    continue;
                }
                $comment = ZendeskCommentData::from($commentArray);
                $callback($comment);
            }

            $cursor = $this->getNextCursor($commentsResponse);
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

            $commentArray = $this->objectToNormalizedArray($event);
            if ($commentArray === null) {
                continue;
            }

            $commentArray['audit_id'] = $auditId;

            return ZendeskCommentData::from($commentArray);
        }

        return null;
    }
}
