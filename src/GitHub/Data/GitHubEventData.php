<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Data;

use Carbon\Carbon;
use Integrations\Adapters\GitHub\Enums\GitHubEventType;
use Spatie\LaravelData\Attributes\WithoutValidation;
use Spatie\LaravelData\Data;

class GitHubEventData extends Data
{
    /**
     * @param  array{name?: string, color?: string}|null  $label
     * @param  array{login?: string, id?: int}|null  $assignee
     * @param  array{title?: string}|null  $milestone
     * @param  array{from?: string, to?: string}|null  $rename
     * @param  array{issue?: array<string, mixed>, type?: string}|null  $source
     */
    public function __construct(
        #[WithoutValidation]
        public readonly int|string $id,
        public readonly GitHubEventType $event,
        public readonly string $url,
        public readonly string $node_id,
        public readonly Carbon $created_at,
        public readonly ?GitHubUserData $actor = null,
        public readonly ?array $label = null,
        public readonly ?array $assignee = null,
        public readonly ?array $milestone = null,
        public readonly ?array $rename = null,
        public readonly ?string $commit_id = null,
        public readonly ?string $commit_url = null,
        public readonly ?string $state_reason = null,
        public readonly ?array $source = null,
        public readonly ?string $source_type = null,
        public readonly ?int $source_number = null,
    ) {}

    /**
     * Create from GitHub API response.
     * Handles cross-referenced events which need synthetic IDs.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createFromGitHubResponse(array $data): self
    {
        $eventValue = $data['event'] ?? '';
        if (! is_string($eventValue)) {
            throw new \InvalidArgumentException('Event type must be a string');
        }
        $event = GitHubEventType::from($eventValue);

        $id = $data['id'] ?? null;
        $url = is_string($data['url'] ?? null) ? $data['url'] : '';
        $nodeId = is_string($data['node_id'] ?? null) ? $data['node_id'] : '';
        $source = $data['source'] ?? null;
        $sourceType = null;
        $sourceNumber = null;

        $createdAt = $data['created_at'] ?? null;
        if (! is_string($createdAt)) {
            throw new \InvalidArgumentException('created_at must be a string');
        }

        if ($id === null && $event === GitHubEventType::CrossReferenced && is_array($source)) {
            $sourceIssue = $source['issue'] ?? null;
            $sourceNumber = is_array($sourceIssue) ? ($sourceIssue['number'] ?? 0) : 0;
            if (! is_int($sourceNumber)) {
                $sourceNumber = 0;
            }
            $timestamp = Carbon::parse($createdAt)->timestamp;
            $id = "xref-{$sourceNumber}-{$timestamp}";

            $sourceType = is_string($source['type'] ?? null) ? $source['type'] : null;

            if (is_array($sourceIssue)) {
                $url = is_string($sourceIssue['url'] ?? null) ? $sourceIssue['url'] : '';
                $nodeId = is_string($sourceIssue['node_id'] ?? null) ? $sourceIssue['node_id'] : '';
            }
        }

        $idValue = $id ?? 'unknown';
        $data['id'] = is_int($idValue) ? (string) $idValue : $idValue;
        $data['url'] = $url;
        $data['node_id'] = $nodeId;
        $data['source_type'] = $sourceType;
        $data['source_number'] = $sourceNumber;

        return self::from($data);
    }

    public function formatDescription(): string
    {
        return $this->event->description([
            'label' => $this->label,
            'assignee' => $this->assignee,
            'milestone' => $this->milestone,
            'rename' => $this->rename,
            'commit_id' => $this->commit_id,
            'state_reason' => $this->state_reason,
            'source_type' => $this->source_type,
            'source_number' => $this->source_number,
            'source' => $this->source,
        ]);
    }

    public function isCommentEvent(): bool
    {
        return $this->event === GitHubEventType::Commented;
    }
}
