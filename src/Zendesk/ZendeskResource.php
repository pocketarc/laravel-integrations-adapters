<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk;

use Integrations\Adapters\Concerns\HandlesErrors;
use Integrations\Models\Integration;
use stdClass;
use Zendesk\API\HttpClient as ZendeskAPI;

use function Safe\json_decode;
use function Safe\json_encode;

abstract class ZendeskResource
{
    use HandlesErrors;

    public function __construct(
        protected readonly Integration $integration,
        protected readonly ZendeskClient $client,
    ) {}

    protected function sdk(): ZendeskAPI
    {
        return $this->client->getSdkClient();
    }

    /**
     * `Zendesk\API\Http::send()` calls `json_decode($body)` without `assoc=true`,
     * so it returns `stdClass` trees. Wrap those callsites with this helper to
     * hand `Spatie\LaravelData\Data::from()` an array, which is what its
     * `Collection<int, T>` validation rules expect.
     */
    protected static function decodeSdkResponse(mixed $response): mixed
    {
        return $response instanceof stdClass
            ? json_decode(json_encode($response, JSON_THROW_ON_ERROR), true)
            : $response;
    }
}
