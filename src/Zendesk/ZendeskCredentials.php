<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk;

use Spatie\LaravelData\Data;

class ZendeskCredentials extends Data
{
    public function __construct(
        public readonly string $email,
        public readonly string $token,
    ) {}
}
