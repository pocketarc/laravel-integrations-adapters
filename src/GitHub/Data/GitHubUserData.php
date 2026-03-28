<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Data;

use Spatie\LaravelData\Data;

class GitHubUserData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $login,
        public readonly string $node_id,
        public readonly string $avatar_url,
        public readonly string $url,
        public readonly string $html_url,
        public readonly string $type,
        public readonly bool $site_admin,
        public readonly ?string $name = null,
        public readonly ?string $email = null,
    ) {}

    public function getEmail(): string
    {
        return $this->email ?? "{$this->login}@github.local";
    }

    public function getDisplayName(): string
    {
        return $this->name ?? $this->login;
    }
}
