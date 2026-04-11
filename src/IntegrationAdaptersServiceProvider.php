<?php

declare(strict_types=1);

namespace Integrations\Adapters;

use Illuminate\Support\ServiceProvider;
use Integrations\Adapters\GitHub\GitHubProvider;
use Integrations\Adapters\Zendesk\ZendeskProvider;
use Integrations\IntegrationManager;

class IntegrationAdaptersServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        IntegrationManager::registerDefaults([
            'github' => GitHubProvider::class,
            'zendesk' => ZendeskProvider::class,
        ]);
    }
}
