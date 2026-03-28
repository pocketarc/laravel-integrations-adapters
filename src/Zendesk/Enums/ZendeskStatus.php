<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Enums;

enum ZendeskStatus: string
{
    case New = 'new';
    case Open = 'open';
    case Pending = 'pending';
    case Hold = 'hold';
    case Solved = 'solved';
    case Closed = 'closed';
    case Deleted = 'deleted';

    public function isResolved(): bool
    {
        return in_array($this, self::closedStatuses(), true);
    }

    public function isDeleted(): bool
    {
        return $this === self::Deleted;
    }

    /** @return list<self> */
    public static function closedStatuses(): array
    {
        return [self::Solved, self::Closed, self::Deleted];
    }

    /** @return list<self> */
    public static function openStatuses(): array
    {
        return [self::New, self::Open, self::Pending, self::Hold];
    }
}
