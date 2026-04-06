<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Data;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\WithoutValidation;
use Spatie\LaravelData\Data;

class GitHubIssueData extends Data
{
    /**
     * @param  array<int, array{login?: string, id?: int}>  $assignees
     * @param  array<int, array{name?: string, color?: string}>  $labels
     * @param  Collection<int, GitHubAttachmentData>  $attachments
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly int $id,
        public readonly int $number,
        public readonly string $node_id,
        public readonly string $url,
        public readonly string $html_url,
        public readonly string $title,
        public readonly ?string $body,
        public readonly string $state,
        public readonly ?string $state_reason,
        public readonly GitHubUserData $user,
        public readonly Carbon $created_at,
        public readonly Carbon $updated_at,
        public readonly int $comments = 0,
        public readonly bool $locked = false,
        #[WithoutValidation]
        public readonly array $assignees = [],
        #[WithoutValidation]
        public readonly array $labels = [],
        public readonly ?Carbon $closed_at = null,
        #[DataCollectionOf(GitHubAttachmentData::class)]
        public readonly Collection $attachments = new Collection,
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

        $body = is_string($properties['body'] ?? null) ? $properties['body'] : '';
        $bodyHtml = is_string($properties['body_html'] ?? null) ? $properties['body_html'] : '';

        $properties['attachments'] = GitHubAttachmentData::extractFromContent($bodyHtml, $body)
            ->map(fn (GitHubAttachmentData $a) => $a->toArray())
            ->toArray();
        $properties['body'] = $body;

        return $properties;
    }
}
