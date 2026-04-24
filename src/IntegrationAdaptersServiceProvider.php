<?php

declare(strict_types=1);

namespace Integrations\Adapters;

use Illuminate\Support\ServiceProvider;
use Integrations\Adapters\GitHub\GitHubProvider;
use Integrations\Adapters\Postmark\PostmarkProvider;
use Integrations\Adapters\Stripe\StripeProvider;
use Integrations\Adapters\Zendesk\ZendeskProvider;
use Integrations\IntegrationManager;

class IntegrationAdaptersServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        IntegrationManager::registerDefaults([
            'github' => GitHubProvider::class,
            'postmark' => PostmarkProvider::class,
            'stripe' => StripeProvider::class,
            'zendesk' => ZendeskProvider::class,
        ]);
    }

    public function boot(): void
    {
        // Postmark is the only adapter that needs to wire itself into a host
        // framework subsystem (Laravel's mail config). The hook is lazy —
        // see PostmarkProvider::registerMailerOverride() — so non-mail
        // requests don't pay any cost for this.
        PostmarkProvider::registerMailerOverride();
    }
}
