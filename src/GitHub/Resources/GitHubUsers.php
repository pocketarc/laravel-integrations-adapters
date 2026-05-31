<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Resources;

use Github\Api\CurrentUser;
use Integrations\Adapters\GitHub\GitHubResource;
use Integrations\RequestContext;
use RuntimeException;

class GitHubUsers extends GitHubResource
{
    /**
     * The user the token authenticates as (GitHub `GET /user`).
     *
     * @return array<string, mixed>
     */
    public function authenticated(): array
    {
        /** @var array<string, mixed> */
        return $this->integration
            ->at('user')
            ->get(function (RequestContext $ctx): array {
                $result = $this->currentUserApi()->show();
                $this->reportGitHubMetadata($ctx);

                if (! is_array($result)) {
                    throw new RuntimeException('Expected an array from GitHub GET /user, got '.get_debug_type($result).'.');
                }

                return $result;
            });
    }

    private function currentUserApi(): CurrentUser
    {
        $api = $this->sdk()->api('current_user');
        if (! $api instanceof CurrentUser) {
            throw new RuntimeException('Expected CurrentUser API, got '.get_debug_type($api).'.');
        }

        return $api;
    }
}
