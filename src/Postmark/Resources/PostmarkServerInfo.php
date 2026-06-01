<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark\Resources;

use Integrations\Adapters\Postmark\PostmarkResource;

class PostmarkServerInfo extends PostmarkResource
{
    /**
     * The Postmark server the token authenticates as (Postmark `GET /server`).
     * A server token is scoped to one server, so the server is the principal
     * behind the credentials.
     *
     * @return array<string, mixed>
     */
    public function retrieve(): array
    {
        /** @var array<string, mixed> */
        return $this->integration
            ->at('server')
            ->get(fn (): array => get_object_vars($this->sdk()->getServer()));
    }
}
