<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Spatie\LaravelData\Data;

class ZendeskThumbnailData extends Data
{
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
    ) {}
}
