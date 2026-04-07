<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class ZendeskCommentData extends Data
{
    /**
     * @param  Collection<int, ZendeskAttachmentData>  $attachments
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly int $author_id,
        public readonly int $audit_id,
        public readonly string $body,
        public readonly string $html_body,
        public readonly string $plain_body,
        public readonly bool $public,
        #[DataCollectionOf(ZendeskAttachmentData::class)]
        public readonly Collection $attachments,
        public readonly ZendeskViaData $via,
        public readonly Carbon $created_at,
        public readonly array $metadata = [],
        public readonly ?array $original = null,
    ) {}

    /**
     * @param  array<mixed>  $properties
     * @return array<mixed>
     */
    #[\Override]
    public static function prepareForPipeline(array $properties): array
    {
        $properties['original'] ??= $properties;

        return $properties;
    }

    public function hasAttachments(): bool
    {
        return $this->attachments->isNotEmpty();
    }

    /**
     * @return Collection<int, ZendeskAttachmentData>
     */
    public function getImageAttachments(): Collection
    {
        return $this->attachments->filter(
            fn (ZendeskAttachmentData $attachment): bool => str_starts_with($attachment->content_type, 'image/')
        );
    }

    public function getMetadata(string $path, mixed $default = null): mixed
    {
        return data_get($this->metadata, $path, $default);
    }
}
