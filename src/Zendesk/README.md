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

| Credentials                            | Metadata                                                       |
|----------------------------------------|----------------------------------------------------------------|
| `email` (string) - Zendesk admin email | `subdomain` (string) - Zendesk subdomain                       |
| `token` (string) - API token           | `custom_domain` (?string) - optional custom help center domain |

The health check uses `custom_domain` if set, otherwise falls back to `https://{subdomain}.zendesk.com`.

## Client methods

```php
$client = new ZendeskClient($integration);
```

| Method                                            | Description                                                                                                    |
|---------------------------------------------------|----------------------------------------------------------------------------------------------------------------|
| `getTickets($callback)`                           | Iterate all tickets via the SDK iterator.                                                                      |
| `getTicketsSince($since, $callback)`              | Incremental ticket export with sideloaded users. Callback receives `ZendeskTicketData` and `?ZendeskUserData`. |
| `getTicketsNewerThan($minId, $callback)`          | Fetch tickets with ID > `$minId` via Search API. For catching missed items.                                    |
| `getUsers($callback)`                             | Iterate all users. Returns a `Collection<ZendeskUserData>`.                                                    |
| `getTicketComments($ticketId, $callback)`         | Iterate comments on a ticket (cursor-paginated).                                                               |
| `getTicket($ticketId)`                            | Get a single ticket.                                                                                           |
| `getUser($userId)`                                | Get a single user.                                                                                             |
| `closeTicket($ticketId)`                          | Set ticket status to "solved".                                                                                 |
| `reopenTicket($ticketId)`                         | Set ticket status to "open".                                                                                   |
| `addComment($ticketId, $body)`                    | Add a public comment to a ticket.                                                                              |
| `addInternalNote($ticketId, $body)`               | Add an internal note (not visible to requester).                                                               |
| `downloadAttachment($url)`                        | Download an attachment by content URL.                                                                         |
| `getFreshAttachmentUrl($ticketId, $attachmentId)` | Get a fresh (non-expired) content URL for an attachment.                                                       |

All methods go through `Integration::request()` internally, so every API call is logged, health-tracked, and rate-limited.

## Sync

The adapter syncs tickets via the Zendesk Incremental Tickets API (`getTicketsSince()`). Each ticket dispatches a `ZendeskTicketSynced` event with both the ticket data and the requester's user data (sideloaded). Failed items dispatch `ZendeskTicketSyncFailed` and don't advance the sync cursor past them. After the sync completes, `ZendeskSyncCompleted` fires with the `SyncResult`.

First sync (null cursor) fetches all tickets from the beginning of time. Set `sync_cursor` on the integration to control the starting point:

```php
$integration->updateSyncCursor('2024-05-01T00:00:00+00:00');
```

Subsequent syncs subtract a 1-hour buffer from the cursor to catch items updated between syncs. Consumers should use `updateOrCreate()` in their event listeners since overlap is expected.

Defaults: 5-minute sync interval, 100 requests/minute rate limit.

## Data classes

| Class                           | Description                                                                                                                                                                         |
|---------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `ZendeskTicketData`             | Ticket with status, requester, assignee, custom fields, satisfaction rating, tags. Stores original API response.                                                                    |
| `ZendeskCommentData`            | Comment with body (plain/HTML), attachments, via channel. Has `hasAttachments()` and `getImageAttachments()` helpers. Stores original API response.                                 |
| `ZendeskUserData`               | User with role, org, locale, timezone, phone, photo. Created via `createFromZendeskResponse()` which handles email fallback for users without emails. Stores original API response. |
| `ZendeskAttachmentData`         | Attachment with file name, content type, size, dimensions, malware scan result, thumbnails.                                                                                         |
| `ZendeskCustomFieldData`        | Custom field ID + value pair.                                                                                                                                                       |
| `ZendeskViaData`                | Channel and source info (how the ticket/comment was created).                                                                                                                       |
| `ZendeskSatisfactionRatingData` | Satisfaction survey score.                                                                                                                                                          |
| `ZendeskPhotoData`              | User profile photo with thumbnails.                                                                                                                                                 |
| `ZendeskThumbnailData`          | Thumbnail image for attachments/photos.                                                                                                                                             |

## Enums

| Enum            | Values                                                                                                                                            |
|-----------------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| `ZendeskStatus` | `New`, `Open`, `Pending`, `Hold`, `Solved`, `Closed`, `Deleted`. Has `isResolved()`, `isDeleted()`, `closedStatuses()`, `openStatuses()` helpers. |
