<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Enums;

enum GitHubIssueStateReason: string
{
    case Completed = 'completed';
    case NotPlanned = 'not_planned';
    case Duplicate = 'duplicate';
    case Reopened = 'reopened';

    public function description(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::NotPlanned => 'Not planned',
            self::Duplicate => 'Duplicate',
            self::Reopened => 'Reopened',
        };
    }
}
