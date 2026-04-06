# GitHub adapter

Wraps the [knplabs/github-api](https://github.com/KnpLabs/php-github-api) SDK. Currently focused on issues (not PRs, releases, etc.).

## Setup

```php
// config/integrations.php
'providers' => [
    'github' => \Integrations\Adapters\GitHub\GitHubProvider::class,
],
```

```php
$integration = Integration::create([
    'provider' => 'github',
    'name' => 'My Repo',
    'credentials' => ['token' => 'ghp_...'],
    'metadata' => ['owner' => 'acme', 'repo' => 'widgets'],
]);
```

| Credentials                                     | Metadata                            |
|-------------------------------------------------|-------------------------------------|
| `token` (string) - GitHub personal access token | `owner` (string) - repository owner |
|                                                 | `repo` (string) - repository name   |

## Client methods

```php
$client = new GitHubClient($integration);
```

| Method                                 | Description                                                                |
|----------------------------------------|----------------------------------------------------------------------------|
| `createIssue($title, $body, $labels)`  | Create an issue. Returns `GitHubIssueData`.                                |
| `getIssue($number)`                    | Get a single issue by number.                                              |
| `getIssuesSince($since, $callback)`    | Iterate issues updated since a timestamp. Skips PRs.                       |
| `getIssueComments($number, $callback)` | Iterate all comments on an issue.                                          |
| `getIssueTimeline($number, $callback)` | Iterate timeline events (labels, assignments, etc.).                       |
| `closeIssue($number, $stateReason)`    | Close an issue. Optional state reason (completed, not_planned, duplicate). |
| `reopenIssue($number)`                 | Reopen a closed issue.                                                     |
| `addComment($number, $body)`           | Add a comment to an issue.                                                 |
| `downloadGitHubAsset($url)`            | Download an asset with token auth for GitHub-hosted URLs.                  |

All methods go through `Integration::request()` / `requestAs()` internally, so every API call is logged, health-tracked, and rate-limited. The provider implements `CustomizesRetry` so the core handles retry for GitHub SDK exceptions (rate limits, server errors, connection failures) with method-aware defaults (GET = 3 attempts, non-GET = 1).

## Sync

The adapter syncs issues via `getIssuesSince()`. Each issue dispatches a `GitHubIssueSynced` event. Failed items dispatch `GitHubIssueSyncFailed` and don't advance the sync cursor past them. After the sync completes, `GitHubSyncCompleted` fires with the `SyncResult`.

First sync (null cursor) fetches all issues from the beginning of time. Set `sync_cursor` on the integration to control the starting point:

```php
$integration->updateSyncCursor('2024-05-01T00:00:00+00:00');
```

Every sync (including the first one with a seeded cursor) subtracts a 1-hour buffer from the cursor. This buffer catches items updated between syncs. Consumers should use `updateOrCreate()` in their event listeners since overlap is expected.

Defaults: 5-minute sync interval, 60 requests/minute rate limit.

## Data classes

| Class                  | Description                                                                                                                                       |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| `GitHubIssueData`      | Issue with body, state, user, labels, assignees, attachments (extracted from body HTML via `prepareForPipeline()`). Stores original API response. |
| `GitHubCommentData`    | Comment with body, user, attachments (extracted from body HTML via `prepareForPipeline()`). Stores original API response.                         |
| `GitHubEventData`      | Timeline event (label, assignment, close, etc.) with formatted descriptions. Handles cross-reference ID synthesis via `prepareForPipeline()`.     |
| `GitHubUserData`       | User with login, avatar, name, email.                                                                                                             |
| `GitHubAttachmentData` | Attachment URL extracted from issue/comment HTML body.                                                                                            |

## Enums

| Enum                     | Values                                                     |
|--------------------------|------------------------------------------------------------|
| `GitHubEventType`        | ~50 timeline event types with human-readable descriptions. |
| `GitHubIssueStateReason` | `Completed`, `NotPlanned`, `Duplicate`, `Reopened`         |
