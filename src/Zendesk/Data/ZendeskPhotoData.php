<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class ZendeskPhotoData extends Data
{
    /**
     * @param  Collection<int, ZendeskThumbnailData>  $thumbnails
     */
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly string $file_name,
        public readonly string $content_url,
        public readonly string $mapped_content_url,
        public readonly string $content_type,
        public readonly int $size,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $inline,
        public readonly bool $deleted,
        #[DataCollectionOf(ZendeskThumbnailData::class)]
        public readonly Collection $thumbnails = new Collection,
    ) {}
}
