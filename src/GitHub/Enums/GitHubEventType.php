<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Enums;

use function data_get;

enum GitHubEventType: string
{
    case Closed = 'closed';
    case Reopened = 'reopened';
    case Labeled = 'labeled';
    case Unlabeled = 'unlabeled';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case Milestoned = 'milestoned';
    case Demilestoned = 'demilestoned';
    case Renamed = 'renamed';
    case Locked = 'locked';
    case Unlocked = 'unlocked';
    case Transferred = 'transferred';
    case Referenced = 'referenced';
    case Mentioned = 'mentioned';
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';
    case ReviewRequested = 'review_requested';
    case ReviewDismissed = 'review_dismissed';
    case MarkedAsDuplicate = 'marked_as_duplicate';
    case UnmarkedAsDuplicate = 'unmarked_as_duplicate';
    case Commented = 'commented';
    case CommentDeleted = 'comment_deleted';
    case CrossReferenced = 'cross-referenced';
    case Committed = 'committed';
    case HeadRefDeleted = 'head_ref_deleted';
    case HeadRefRestored = 'head_ref_restored';
    case BaseRefChanged = 'base_ref_changed';
    case ConvertedNoteToIssue = 'converted_note_to_issue';
    case Moved = 'moved';
    case AddedToProject = 'added_to_project';
    case RemovedFromProject = 'removed_from_project';
    case AddedToProjectV2 = 'added_to_project_v2';
    case RemovedFromProjectV2 = 'removed_from_project_v2';
    case ProjectV2ItemStatusChanged = 'project_v2_item_status_changed';
    case ConvertedToDiscussion = 'converted_to_discussion';
    case IssueTypeAdded = 'issue_type_added';
    case IssueTypeChanged = 'issue_type_changed';
    case IssueTypeRemoved = 'issue_type_removed';
    case SubIssueAdded = 'sub_issue_added';
    case SubIssueRemoved = 'sub_issue_removed';
    case ParentIssueAdded = 'parent_issue_added';
    case ParentIssueRemoved = 'parent_issue_removed';
    case Pinned = 'pinned';
    case Unpinned = 'unpinned';

    /**
     * @param  array<string, mixed>|null  $eventData
     */
    public function description(?array $eventData = null): string
    {
        return match ($this) {
            self::Closed => match (self::str($eventData, 'state_reason')) {
                'completed' => 'Closed the issue as completed, which means that the development team has resolved whatever the issue was',
                'duplicate' => 'Closed the issue as duplicate, which means that there is another ticket that tracks the same issue',
                'not_planned' => 'Closed the issue as not planned, which means that the issue will not be addressed in the foreseeable future',
                default => 'Closed the issue',
            },
            self::Reopened => 'Reopened the issue',
            self::Labeled => 'Added label: '.self::str($eventData, 'label.name', 'unknown'),
            self::Unlabeled => 'Removed label: '.self::str($eventData, 'label.name', 'unknown'),
            self::Assigned => 'Assigned to: '.self::str($eventData, 'assignee.login', 'unknown'),
            self::Unassigned => 'Unassigned from: '.self::str($eventData, 'assignee.login', 'unknown'),
            self::Milestoned => 'Added to milestone: '.self::str($eventData, 'milestone.title', 'unknown'),
            self::Demilestoned => 'Removed from milestone: '.self::str($eventData, 'milestone.title', 'unknown'),
            self::Renamed => 'Renamed from "'.self::str($eventData, 'rename.from').'" to "'.self::str($eventData, 'rename.to').'"',
            self::Locked => 'Locked the issue',
            self::Unlocked => 'Unlocked the issue',
            self::Transferred => 'Transferred the issue',
            self::Referenced => 'Referenced in commit '.self::str($eventData, 'commit_id', 'unknown'),
            self::Mentioned => 'Mentioned in issue or PR',
            self::Subscribed => 'Subscribed to the issue',
            self::Unsubscribed => 'Unsubscribed from the issue',
            self::ReviewRequested => 'Requested review',
            self::ReviewDismissed => 'Dismissed review',
            self::MarkedAsDuplicate => 'Marked as duplicate',
            self::UnmarkedAsDuplicate => 'Unmarked as duplicate',
            self::Commented => 'Commented on the issue',
            self::CommentDeleted => 'Deleted a comment',
            self::CrossReferenced => match (self::str($eventData, 'source_type')) {
                'issue' => 'Cross-referenced in #'.self::str($eventData, 'source_number', 'unknown'),
                'pull_request' => 'Cross-referenced in PR #'.self::str($eventData, 'source_number', 'unknown'),
                default => 'Cross-referenced',
            },
            self::Committed => 'Committed',
            self::HeadRefDeleted => 'Head ref deleted',
            self::HeadRefRestored => 'Head ref restored',
            self::BaseRefChanged => 'Base ref changed',
            self::ConvertedNoteToIssue => 'Converted note to issue',
            self::Moved => 'Moved',
            self::AddedToProject => 'Added to project',
            self::RemovedFromProject => 'Removed from project',
            self::AddedToProjectV2 => 'Added to project',
            self::RemovedFromProjectV2 => 'Removed from project',
            self::ProjectV2ItemStatusChanged => 'Project status changed',
            self::ConvertedToDiscussion => 'Converted to discussion',
            self::IssueTypeAdded => 'Added issue type'.self::str($eventData, 'issue_type.name'),
            self::IssueTypeChanged => self::describeIssueTypeChanged($eventData),
            self::IssueTypeRemoved => 'Removed issue type'.self::prefixIfNotEmpty(': ', self::str($eventData, 'issue_type.name')),
            self::SubIssueAdded => 'Added sub-issue',
            self::SubIssueRemoved => 'Removed sub-issue',
            self::ParentIssueAdded => 'Added parent issue',
            self::ParentIssueRemoved => 'Removed parent issue',
            self::Pinned => 'Pinned the issue',
            self::Unpinned => 'Unpinned the issue',
        };
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private static function str(?array $data, string $key, string $default = ''): string
    {
        $value = data_get($data, $key, $default);

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    private static function prefixIfNotEmpty(string $prefix, string $value): string
    {
        return $value !== '' ? $prefix.$value : '';
    }

    /** @param array<string, mixed>|null $eventData */
    private static function describeIssueTypeChanged(?array $eventData): string
    {
        $from = self::str($eventData, 'from');
        $to = self::str($eventData, 'to');

        if ($from !== '' && $to !== '') {
            return 'Changed issue type from "'.$from.'" to "'.$to.'"';
        }

        return 'Changed issue type';
    }
}
