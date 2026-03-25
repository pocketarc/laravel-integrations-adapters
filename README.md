# Laravel Integrations Adapters
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Adapter implementations for [`pocketarc/laravel-integrations`](https://github.com/pocketarc/laravel-integrations).

These aren't fully API-complete. They cover what's needed for the projects that use them. If you need a more complete implementation, you can extend these or build your own using the core package's contracts.

## Available adapters

| Adapter | Capabilities                            |
|---------|-----------------------------------------|
| Zendesk | `IntegrationProvider`, `HasHealthCheck` |
| GitHub  | `IntegrationProvider`, `HasHealthCheck` |

## Installation

```bash
composer require pocketarc/laravel-integrations-adapters
```

## Usage

Register adapters in your `config/integrations.php`:

```php
'providers' => [
    'zendesk' => \Integrations\Adapters\Zendesk\ZendeskProvider::class,
    'github' => \Integrations\Adapters\GitHub\GitHubProvider::class,
],
```

Or programmatically:

```php
use Integrations\Facades\Integrations;

Integrations::register('zendesk', \Integrations\Adapters\Zendesk\ZendeskProvider::class);
Integrations::register('github', \Integrations\Adapters\GitHub\GitHubProvider::class);
```

Then create integrations with typed credentials and metadata:

```php
use Integrations\Models\Integration;

$integration = Integration::create([
    'provider' => 'zendesk',
    'name' => 'Production Zendesk',
    'credentials' => [
        'email' => 'admin@acme.com',
        'token' => 'your-api-token',
    ],
    'metadata' => [
        'subdomain' => 'acme',
    ],
]);

// Credentials are encrypted at rest and cast to typed Data classes:
$integration->credentials->email; // 'admin@acme.com'
$integration->metadata->subdomain; // 'acme'
```

## License

MIT. See [LICENSE](LICENSE) for details.
