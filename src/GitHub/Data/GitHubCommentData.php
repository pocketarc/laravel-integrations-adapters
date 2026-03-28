<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Data;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\WithoutValidation;
use Spatie\LaravelData\Data;

class GitHubCommentData extends Data
{
    /**
     * @param  Collection<int, GitHubAttachmentData>  $attachments
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly int $id,
        public readonly string $node_id,
        public readonly string $url,
        public readonly string $html_url,
        public readonly string $issue_url,
        #[WithoutValidation]
        public readonly string $body,
        public readonly GitHubUserData $user,
        public readonly Carbon $created_at,
        public readonly Carbon $updated_at,
        public readonly string $author_association = 'NONE',
        #[DataCollectionOf(GitHubAttachmentData::class)]
        public readonly Collection $attachments = new Collection,
        public readonly ?array $original = null,
    ) {}

    /**
     * Create from GitHub API response.
     * Extracts attachments from body HTML.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createFromGitHubResponse(array $data): self
    {
        $original = $data;

        $body = is_string($data['body'] ?? null) ? $data['body'] : '';
        $bodyHtml = is_string($data['body_html'] ?? null) ? $data['body_html'] : '';

        $data['attachments'] = GitHubAttachmentData::extractFromContent($bodyHtml, $body)
            ->map(fn (GitHubAttachmentData $a) => $a->toArray())
            ->toArray();
        $data['body'] = $body;
        $data['original'] = $original;

        return self::from($data);
    }
}
