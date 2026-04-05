<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Resources;

use Illuminate\Support\Collection;
use Integrations\Adapters\Zendesk\Data\ZendeskUserData;
use Integrations\Adapters\Zendesk\ZendeskResource;
use stdClass;

class ZendeskUsers extends ZendeskResource
{
    public function get(int $userId): ?stdClass
    {
        return $this->executeWithErrorHandling(function () use ($userId): ?stdClass {
            $result = $this->integration
                ->to("users/{$userId}.json")
                ->get(function () use ($userId): ?stdClass {
                    $response = $this->sdk()->users()->find($userId);
                    $user = $response->user ?? null;

                    return $user instanceof stdClass ? $user : null;
                });

            return $result instanceof stdClass ? $result : null;
        });
    }

    /**
     * Iterate through all users and call the callback for each.
     *
     * @param  (callable(ZendeskUserData): void)|null  $callback
     *
     * @param-immediately-invoked-callable $callback
     *
     * @return Collection<int, ZendeskUserData>
     */
    public function list(?callable $callback = null): Collection
    {
        /** @var Collection<int, ZendeskUserData> $users */
        $users = new Collection;

        $this->integration
            ->to('users.json')
            ->get(function () use ($callback, &$users): void {
                $iterator = $this->sdk()->users()->iterator();

                foreach ($iterator as $user) {
                    if (! is_object($user)) {
                        continue;
                    }
                    $data = ZendeskUserData::createFromZendeskResponse($user);
                    if ($callback !== null) {
                        $callback($data);
                    }

                    $users->push($data);
                }
            },
            );

        return $users;
    }
}
