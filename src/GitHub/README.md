# GitHub adapter

Wraps the [knplabs/github-api](https://github.com/KnpLabs/php-github-api) SDK. Currently focused on issues (not PRs, releases, etc.).

## Installation

```bash
composer require pocketarc/laravel-integrations-adapters
```

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

## Resources

```php
$client = new GitHubClient($integration);
```

| Resource              | Method                             | Description                                                                |
|-----------------------|------------------------------------|----------------------------------------------------------------------------|
| `$client->issues()`   | `->create($title, $body, $labels, $idempotencyKey?)` | Create an issue. Returns `GitHubIssueData`.                          |
|                       | `->get($number)`                   | Get a single issue by number.                                              |
|                       | `->since($since, $callback)`       | Iterate issues updated since a timestamp. Skips PRs.                       |
|                       | `->close($number, $stateReason, $idempotencyKey?)` | Close an issue. Optional state reason (completed, not_planned, duplicate). |
|                       | `->reopen($number, $idempotencyKey?)` | Reopen a closed issue.                                                  |
|                       | `->timeline($number, $callback)`   | Iterate timeline events (labels, assignments, etc.).                       |
| `$client->comments()` | `->list($number, $callback)`       | Iterate all comments on an issue.                                          |
|                       | `->add($number, $body, $idempotencyKey?)` | Add a comment to an issue. Returns `?GitHubCommentData`.            |
| `$client->assets()`   | `->download($url)`                 | Download an asset with token auth for GitHub-hosted URLs.                  |

All methods go through `Integration::request()` / `requestAs()` internally, so every API call is logged, health-tracked, and rate-limited. The provider implements `CustomizesRetry` so the core handles retry for GitHub SDK exceptions (rate limits, server errors, connection failures) with method-aware defaults (GET = 3 attempts, non-GET = 1).

### Idempotency

All write methods (`issues()->create`, `close`, `reopen`, `comments()->add`) accept an optional `$idempotencyKey`. Pass a stable, application-meaningful value (e.g. `"open-issue:order-{$order->id}"`, `"close-issue:{$issueNumber}"`) when you need at-most-once execution: the package writes a row in `integration_idempotency_keys` before the call fires, throws `Integrations\Exceptions\IdempotencyConflict` on a second call with the same key, and lets you skip the work. GitHub itself doesn't natively dedupe by header, so the local row is the only protection here. Pass `null` (the default) to skip idempotency entirely. See the [core idempotency guide](https://laravel-integrations.pocketarc.com/core-concepts/idempotency).

## Sync

The adapter syncs issues via `$client->issues()->since()`. For each issue it calls `$session->dispatch()` with a `GitHubIssueSynced` event. The framework wraps each one in a queued job, runs your listeners, and advances `sync_cursor` once every issue's job has succeeded. Listeners for `GitHubIssueSynced` must not implement `ShouldQueue` (see the core [Scheduled syncs](https://laravel-integrations.pocketarc.com/features/scheduled-syncs) guide).

First sync (null cursor) fetches all issues from timestamp 0. Set `sync_cursor` on the integration to control the starting point:

```php
$integration->updateSyncCursor('2024-05-01T00:00:00+00:00');
```

Every incremental sync subtracts a 1-hour buffer from the cursor to catch issues updated between runs. The framework's cursor advance is monotonic, so re-presenting items inside that window can't regress progress. Consumers should still use `updateOrCreate()` in their listeners since overlap is expected.

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
