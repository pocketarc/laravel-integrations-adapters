# Zendesk adapter

Wraps the [zendesk/zendesk_api_client_php](https://github.com/zendesk/zendesk_api_client_php) SDK. Focused on tickets, users, and comments.

## Setup

```php
// config/integrations.php
'providers' => [
    'zendesk' => \Integrations\Adapters\Zendesk\ZendeskProvider::class,
],
```

```php
$integration = Integration::create([
    'provider' => 'zendesk',
    'name' => 'Production Zendesk',
    'credentials' => ['email' => 'admin@acme.com', 'token' => 'your-api-token'],
    'metadata' => ['subdomain' => 'acme'],
]);
```

| Credentials                            | Metadata                                                                                    |
|----------------------------------------|---------------------------------------------------------------------------------------------|
| `email` (string) - Zendesk admin email | `subdomain` (string) - Zendesk subdomain                                                    |
| `token` (string) - API token           | `custom_domain` (?string) - full base URL including scheme, e.g. `https://support.acme.com` |

The health check appends `/api/v2/users/me.json` to `custom_domain` if set, otherwise uses `https://{subdomain}.zendesk.com`.

## Client methods

```php
$client = new ZendeskClient($integration);
```

| Method                                            | Description                                                                                                    |
|---------------------------------------------------|----------------------------------------------------------------------------------------------------------------|
| `getTickets($callback)`                           | Iterate all tickets via the SDK iterator.                                                                      |
| `getTicketsSince($since, $callback)`              | Incremental ticket export with sideloaded users. Callback receives `ZendeskTicketData` and `?ZendeskUserData`. |
| `getTicketsNewerThan($minId, $callback)`          | Fetch tickets with ID > `$minId` via Search API. For catching missed items.                                    |
| `getUsers($callback?)`                            | Iterate all users. Optional callback receives each `ZendeskUserData`. Returns `Collection<ZendeskUserData>`.   |
| `getTicketComments($ticketId, $callback)`         | Iterate comments on a ticket (cursor-paginated).                                                               |
| `getTicket($ticketId)`                            | Get a single ticket.                                                                                           |
| `getUser($userId)`                                | Get a single user.                                                                                             |
| `closeTicket($ticketId)`                          | Set ticket status to "solved".                                                                                 |
| `reopenTicket($ticketId)`                         | Set ticket status to "open".                                                                                   |
| `addComment($ticketId, $body)`                    | Add a public comment to a ticket.                                                                              |
| `addInternalNote($ticketId, $body)`               | Add an internal note (not visible to requester).                                                               |
| `downloadAttachment($url)`                        | Download an attachment by content URL.                                                                         |
| `getFreshAttachmentUrl($ticketId, $attachmentId)` | Get a fresh (non-expired) content URL for an attachment.                                                       |

All methods go through `Integration::request()` / `requestAs()` internally, so every API call is logged, health-tracked, and rate-limited. Retry is handled by the core with method-aware defaults (GET = 3 attempts, non-GET = 1). The Zendesk SDK wraps Guzzle exceptions, which the core detects via exception chain walking and respects `Retry-After` headers automatically.

## Sync

The adapter syncs tickets via the Zendesk Incremental Tickets API (`getTicketsSince()`). Each ticket dispatches a `ZendeskTicketSynced` event with both the ticket data and the requester's user data (sideloaded). Failed items dispatch `ZendeskTicketSyncFailed` and don't advance the sync cursor past them. After the sync completes, `ZendeskSyncCompleted` fires with the `SyncResult`.

First sync (null cursor) fetches all tickets from the beginning of time. Set `sync_cursor` on the integration to control the starting point:

```php
$integration->updateSyncCursor('2024-05-01T00:00:00+00:00');
```

Every sync (including the first one with a seeded cursor) subtracts a 1-hour buffer from the cursor. This buffer catches items updated between syncs. Consumers should use `updateOrCreate()` in their event listeners since overlap is expected.

Defaults: 5-minute sync interval, 100 requests/minute rate limit.

## Data classes

| Class                              | Description                                                                                                                                                    |
|------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `ZendeskTicketData`                | Ticket with status, requester, assignee, custom fields, satisfaction rating, tags. Stores original API response.                                               |
| `ZendeskCommentData`               | Comment with body (plain/HTML), attachments, via channel. Has `hasAttachments()` and `getImageAttachments()` helpers. Stores original API response.            |
| `ZendeskUserData`                  | User with role, org, locale, timezone, phone, photo. Handles email fallback for users without emails via `prepareForPipeline()`. Stores original API response. |
| `ZendeskAttachmentData`            | Attachment with file name, content type, size, dimensions, malware scan result, thumbnails.                                                                    |
| `ZendeskCustomFieldData`           | Custom field ID + value pair.                                                                                                                                  |
| `ZendeskViaData`                   | Channel and source info (how the ticket/comment was created). Normalizes integer channel values to strings via `prepareForPipeline()`.                         |
| `ZendeskSatisfactionRatingData`    | Satisfaction survey score.                                                                                                                                     |
| `ZendeskPhotoData`                 | User profile photo with thumbnails.                                                                                                                            |
| `ZendeskThumbnailData`             | Thumbnail image for attachments/photos.                                                                                                                        |
| `ZendeskIncrementalTicketResponse` | Typed response for the incremental tickets API. Contains `tickets`, `users`, `next_page`, `count`. Has `nextTimestamp()` for pagination.                       |
| `ZendeskSearchResponse`            | Typed response for the search API. Contains `results` (tickets), `users`, `next_page`.                                                                         |
| `ZendeskCommentPageResponse`       | Typed response for the comments endpoint. Contains `comments` and `meta` (pagination).                                                                         |
| `ZendeskPaginationMeta`            | Cursor pagination metadata with `has_more` and `after_cursor`.                                                                                                 |

## Enums

| Enum            | Values                                                                                                                                            |
|-----------------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| `ZendeskStatus` | `New`, `Open`, `Pending`, `Hold`, `Solved`, `Closed`, `Deleted`. Has `isResolved()`, `isDeleted()`, `closedStatuses()`, `openStatuses()` helpers. |
