# Laravel Integrations Adapters

[![CI](https://github.com/pocketarc/laravel-integrations-adapters/actions/workflows/ci.yml/badge.svg)](https://github.com/pocketarc/laravel-integrations-adapters/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/pocketarc/laravel-integrations-adapters)](https://packagist.org/packages/pocketarc/laravel-integrations-adapters)
[![Total Downloads](https://img.shields.io/packagist/dt/pocketarc/laravel-integrations-adapters)](https://packagist.org/packages/pocketarc/laravel-integrations-adapters)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-8892BF?logo=php)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Adapter implementations for [`pocketarc/laravel-integrations`](https://github.com/pocketarc/laravel-integrations). These aren't fully API-complete. They cover what's needed for the projects that use them. You can extend them or build your own using the core package's contracts.

## Installation

```bash
composer require pocketarc/laravel-integrations-adapters
```

Register adapters in `config/integrations.php`:

```php
'providers' => [
    'zendesk' => \Integrations\Adapters\Zendesk\ZendeskProvider::class,
    'github'  => \Integrations\Adapters\GitHub\GitHubProvider::class,
],
```

## Available adapters

| Adapter                | Docs                            |
|------------------------|---------------------------------|
| [GitHub](src/GitHub)   | [README](src/GitHub/README.md)  |
| [Zendesk](src/Zendesk) | [README](src/Zendesk/README.md) |

## License

MIT. See [LICENSE](LICENSE) for details.
