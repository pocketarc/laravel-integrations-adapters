<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Resources;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Integrations\Adapters\Concerns\ValidatesUrls;
use Integrations\Adapters\Zendesk\Data\ZendeskCommentData;
use Integrations\Adapters\Zendesk\Data\ZendeskCommentPageResponse;
use Integrations\Adapters\Zendesk\ZendeskResource;

class ZendeskAttachments extends ZendeskResource
{
    use ValidatesUrls;

    public function download(string $url): ?string
    {
        self::assertUrlNotPrivate($url);

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
                $response = $this->integration
                    ->toAs("tickets/{$ticketId}/comments.json", ZendeskCommentPageResponse::class)
                    ->withData($params)
                    ->get(fn () => $this->sdk()->tickets($ticketId)->comments()->findAll($params));

                if (! $response instanceof ZendeskCommentPageResponse) {
                    return null;
                }

                $url = $this->findAttachmentContentUrl($response->comments, $attachmentId);
                if ($url !== null) {
                    return $url;
                }

                $cursor = $response->meta->has_more ? $response->meta->after_cursor : null;
                if ($cursor !== null) {
                    $params['page[after]'] = $cursor;
                }
            } while ($cursor !== null);

            return null;
        });
    }

    /**
     * @param  Collection<int, ZendeskCommentData>  $comments
     */
    private function findAttachmentContentUrl(Collection $comments, int $attachmentId): ?string
    {
        foreach ($comments as $comment) {
            foreach ($comment->attachments as $attachment) {
                if ($attachment->id === $attachmentId) {
                    return $attachment->content_url;
                }
            }
        }

        return null;
    }
}
