<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Resources;

use Integrations\Adapters\Postmark\Data\PostmarkInboundMessageData;
use Integrations\Adapters\Postmark\Data\PostmarkInboundMessageListResponse;
use Integrations\Adapters\Postmark\Data\PostmarkOutboundMessageData;
use Integrations\Adapters\Postmark\Data\PostmarkOutboundMessageListResponse;
use Integrations\Adapters\Postmark\PostmarkResource;

/**
 * Outbound and inbound message search/inspection. Postmark stores these for
 * 45 days by default (longer on higher plans), so list calls without a
 * date range hit the full retention window. Send/inbound details endpoints
 * return the full email body and metadata, which can be large; prefer the
 * list summaries when you only need routing fields.
 */
class PostmarkMessages extends PostmarkResource
{
    /**
     * @param  array{recipient?: string|null, fromEmail?: string|null, tag?: string|null, subject?: string|null, status?: string|null, fromdate?: string|null, todate?: string|null, metadata?: array<string, string>|null, messagestream?: string|null}  $filters
     */
    public function listOutbound(int $count = 100, int $offset = 0, array $filters = []): ?PostmarkOutboundMessageListResponse
    {
        return $this->executeWithErrorHandling(function () use ($count, $offset, $filters): ?PostmarkOutboundMessageListResponse {
            $result = $this->integration
                ->toAs('messages/outbound', PostmarkOutboundMessageListResponse::class)
                ->withData(array_merge(['count' => $count, 'offset' => $offset], $filters))
                ->get(function () use ($count, $offset, $filters): array {
                    $list = $this->sdk()->getOutboundMessages(
                        count: $count,
                        offset: $offset,
                        recipient: $filters['recipient'] ?? null,
                        fromEmail: $filters['fromEmail'] ?? null,
                        tag: $filters['tag'] ?? null,
                        subject: $filters['subject'] ?? null,
                        status: $filters['status'] ?? null,
                        fromdate: $filters['fromdate'] ?? null,
                        todate: $filters['todate'] ?? null,
                        metadata: $filters['metadata'] ?? null,
                        messagestream: $filters['messagestream'] ?? null,
                    );

                    $messages = [];
                    foreach ($list->Messages as $message) {
                        if (is_object($message)) {
                            $messages[] = get_object_vars($message);
                        }
                    }

                    return [
                        'TotalCount' => $list->TotalCount,
                        'Messages' => $messages,
                    ];
                });

            return $result instanceof PostmarkOutboundMessageListResponse ? $result : null;
        });
    }

    public function getOutbound(string $messageId): ?PostmarkOutboundMessageData
    {
        return $this->executeWithErrorHandling(function () use ($messageId): ?PostmarkOutboundMessageData {
            $result = $this->integration
                ->toAs("messages/outbound/{$messageId}/details", PostmarkOutboundMessageData::class)
                ->get(function () use ($messageId): array {
                    return get_object_vars($this->sdk()->getOutboundMessageDetails($messageId));
                });

            return $result instanceof PostmarkOutboundMessageData ? $result : null;
        });
    }

    /**
     * @param  array{recipient?: string|null, fromEmail?: string|null, tag?: string|null, subject?: string|null, mailboxHash?: string|null, status?: string|null, fromdate?: string|null, todate?: string|null}  $filters
     */
    public function listInbound(int $count = 100, int $offset = 0, array $filters = []): ?PostmarkInboundMessageListResponse
    {
        return $this->executeWithErrorHandling(function () use ($count, $offset, $filters): ?PostmarkInboundMessageListResponse {
            $result = $this->integration
                ->toAs('messages/inbound', PostmarkInboundMessageListResponse::class)
                ->withData(array_merge(['count' => $count, 'offset' => $offset], $filters))
                ->get(function () use ($count, $offset, $filters): array {
                    $list = $this->sdk()->getInboundMessages(
                        count: $count,
                        offset: $offset,
                        recipient: $filters['recipient'] ?? null,
                        fromEmail: $filters['fromEmail'] ?? null,
                        tag: $filters['tag'] ?? null,
                        subject: $filters['subject'] ?? null,
                        mailboxHash: $filters['mailboxHash'] ?? null,
                        status: $filters['status'] ?? null,
                        fromdate: $filters['fromdate'] ?? null,
                        todate: $filters['todate'] ?? null,
                    );

                    $messages = [];
                    foreach ($list->InboundMessages as $message) {
                        if (is_object($message)) {
                            $messages[] = get_object_vars($message);
                        }
                    }

                    return [
                        'TotalCount' => $list->TotalCount,
                        'InboundMessages' => $messages,
                    ];
                });

            return $result instanceof PostmarkInboundMessageListResponse ? $result : null;
        });
    }

    public function getInbound(string $messageId): ?PostmarkInboundMessageData
    {
        return $this->executeWithErrorHandling(function () use ($messageId): ?PostmarkInboundMessageData {
            $result = $this->integration
                ->toAs("messages/inbound/{$messageId}/details", PostmarkInboundMessageData::class)
                ->get(function () use ($messageId): array {
                    return get_object_vars($this->sdk()->getInboundMessageDetails($messageId));
                });

            return $result instanceof PostmarkInboundMessageData ? $result : null;
        });
    }
}
