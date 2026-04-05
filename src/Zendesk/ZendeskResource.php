<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk;

use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Integrations\Adapters\Concerns\RetriesRequests;
use Integrations\Models\Integration;
use stdClass;
use Zendesk\API\Exceptions\ApiResponseException;
use Zendesk\API\HttpClient as ZendeskAPI;

use function Safe\json_decode;
use function Safe\json_encode;

abstract class ZendeskResource
{
    use RetriesRequests;

    public function __construct(
        protected readonly Integration $integration,
        protected readonly ZendeskClient $client,
    ) {}

    protected function sdk(): ZendeskAPI
    {
        return $this->client->getSdkClient();
    }

    /**
     * @return array{int, string}|null
     */
    #[\Override]
    protected function getRetryDelay(\Throwable $e, int $attempt): ?array
    {
        if (! $e instanceof ApiResponseException) {
            return null;
        }

        $statusCode = $this->getStatusCodeFromException($e);

        if ($statusCode === 429) {
            $delay = $this->getRetryAfterDelay($e) ?? 30;

            return [$delay, 'Rate limited (429)'];
        }

        if ($statusCode !== null && $statusCode >= 500 && $statusCode < 600) {
            return [$attempt, "Server error ({$statusCode})"];
        }

        return null;
    }

    private function getStatusCodeFromException(ApiResponseException $e): ?int
    {
        $previous = $e->getPrevious();
        if ($previous instanceof GuzzleRequestException && $previous->getResponse() !== null) {
            return $previous->getResponse()->getStatusCode();
        }

        return null;
    }

    private function getRetryAfterDelay(ApiResponseException $e): ?int
    {
        $previous = $e->getPrevious();
        if (! $previous instanceof GuzzleRequestException || $previous->getResponse() === null) {
            return null;
        }

        $retryAfter = $previous->getResponse()->getHeaderLine('Retry-After');
        if ($retryAfter !== '' && is_numeric($retryAfter)) {
            return min((int) $retryAfter, 60);
        }

        return null;
    }

    protected function getNextCursor(mixed $response): ?string
    {
        if (! $response instanceof stdClass) {
            return null;
        }

        $meta = $response->meta ?? null;
        if (! $meta instanceof stdClass) {
            return null;
        }

        $hasMore = $meta->has_more ?? false;
        if ($hasMore !== true) {
            return null;
        }

        $cursor = $meta->after_cursor ?? null;

        return is_string($cursor) ? $cursor : null;
    }

    /**
     * @return array<mixed, mixed>|null
     */
    protected function objectToNormalizedArray(object $obj): ?array
    {
        $array = json_decode(json_encode($obj), true);
        if (! is_array($array)) {
            return null;
        }

        $original = $array;
        $array = $this->normalizeViaChannel($array);
        $array['original'] = $original;

        return $array;
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>
     */
    protected function normalizeViaChannel(array $data): array
    {
        if (array_key_exists('via', $data) && is_array($data['via']) && array_key_exists('channel', $data['via']) && is_int($data['via']['channel'])) {
            $data['via']['channel'] = (string) $data['via']['channel'];
        }

        return $data;
    }
}
