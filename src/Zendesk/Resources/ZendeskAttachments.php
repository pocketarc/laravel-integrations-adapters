<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Resources;

use Illuminate\Support\Facades\Http;
use Integrations\Adapters\Zendesk\ZendeskResource;
use stdClass;

class ZendeskAttachments extends ZendeskResource
{
    public function download(string $url): ?string
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
    public function freshUrl(int $ticketId, int $attachmentId): ?string
    {
        return $this->executeWithErrorHandling(function () use ($ticketId, $attachmentId): ?string {
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
                    return null;
                }

                $url = $this->findAttachmentContentUrl($commentsResponse->comments, $attachmentId);
                if ($url !== null) {
                    return $url;
                }

                $cursor = $this->getNextCursor($commentsResponse);
                if ($cursor !== null) {
                    $params['page[after]'] = $cursor;
                }
            } while ($cursor !== null);

            return null;
        });
    }

    /**
     * @param  array<mixed, mixed>  $comments
     */
    private function findAttachmentContentUrl(array $comments, int $attachmentId): ?string
    {
        foreach ($comments as $comment) {
            if (! $comment instanceof stdClass) {
                continue;
            }
            $url = $this->findContentUrlInAttachments($comment->attachments ?? [], $attachmentId);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function findContentUrlInAttachments(mixed $attachments, int $attachmentId): ?string
    {
        if (! is_array($attachments)) {
            return null;
        }

        foreach ($attachments as $attachment) {
            if (! $attachment instanceof stdClass) {
                continue;
            }
            if (property_exists($attachment, 'id') && $attachment->id === $attachmentId) {
                return property_exists($attachment, 'content_url') && is_string($attachment->content_url) ? $attachment->content_url : null;
            }
        }

        return null;
    }
}
