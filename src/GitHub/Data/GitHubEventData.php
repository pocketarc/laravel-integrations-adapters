<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Data;

use Illuminate\Support\Carbon;
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
     * @param  array<mixed>  $properties
     * @return array<mixed>
     */
    #[\Override]
    public static function prepareForPipeline(array $properties): array
    {
        $eventValue = $properties['event'] ?? '';
        if (! is_string($eventValue)) {
            throw new \InvalidArgumentException('Event type must be a string');
        }
        $event = GitHubEventType::from($eventValue);

        $id = $properties['id'] ?? null;
        $url = is_string($properties['url'] ?? null) ? $properties['url'] : '';
        $nodeId = is_string($properties['node_id'] ?? null) ? $properties['node_id'] : '';
        $source = $properties['source'] ?? null;

        $createdAt = $properties['created_at'] ?? null;
        if (! is_string($createdAt)) {
            throw new \InvalidArgumentException('created_at must be a string');
        }

        if ($id === null && $event === GitHubEventType::CrossReferenced && is_array($source)) {
            $resolved = self::resolveCrossReference($source, $createdAt, $url, $nodeId);
            $properties['id'] = $resolved['id'];
            $properties['url'] = $resolved['url'];
            $properties['node_id'] = $resolved['node_id'];
            $properties['source_type'] = $resolved['source_type'];
            $properties['source_number'] = $resolved['source_number'];
        } elseif (is_int($id)) {
            $properties['id'] = (string) $id;
        } elseif (! is_string($id)) {
            $properties['id'] = 'unknown';
        }

        return $properties;
    }

    /**
     * @param  array<mixed, mixed>  $source
     * @return array{id: string, url: string, node_id: string, source_type: string|null, source_number: int}
     */
    private static function resolveCrossReference(array $source, string $createdAt, string $fallbackUrl, string $fallbackNodeId): array
    {
        $sourceIssue = is_array($source['issue'] ?? null) ? $source['issue'] : [];
        $sourceNumber = is_int($sourceIssue['number'] ?? null) ? $sourceIssue['number'] : 0;
        $timestamp = Carbon::parse($createdAt)->timestamp;

        $url = is_string($sourceIssue['url'] ?? null) ? $sourceIssue['url'] : $fallbackUrl;
        $nodeId = is_string($sourceIssue['node_id'] ?? null) ? $sourceIssue['node_id'] : $fallbackNodeId;

        return [
            'id' => "xref-{$sourceNumber}-{$timestamp}",
            'url' => $url,
            'node_id' => $nodeId,
            'source_type' => is_string($source['type'] ?? null) ? $source['type'] : null,
            'source_number' => $sourceNumber,
        ];
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
