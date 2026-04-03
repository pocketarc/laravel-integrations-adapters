<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Spatie\LaravelData\Data;

class ZendeskAttachmentData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>|null  $thumbnails
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly string $file_name,
        public readonly string $content_url,
        public readonly string $mapped_content_url,
        public readonly string $content_type,
        public readonly int $size,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly bool $inline = false,
        public readonly bool $deleted = false,
        public readonly bool $malware_access_override = false,
        public readonly string $malware_scan_result = '',
        public readonly ?array $thumbnails = null,
        public readonly ?array $original = null,
    ) {}
}
