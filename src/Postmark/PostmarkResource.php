<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark;

use Illuminate\Support\Carbon;
use Integrations\Adapters\Concerns\HandlesErrors;
use Integrations\Models\Integration;
use InvalidArgumentException;
use Postmark\PostmarkClient as PostmarkSdkClient;

abstract class PostmarkResource
{
    use HandlesErrors;

    public function __construct(
        protected readonly Integration $integration,
        protected readonly PostmarkClient $client,
    ) {}

    protected function sdk(): PostmarkSdkClient
    {
        return $this->client->getSdkClient();
    }

    /**
     * Guard against forwarding obviously-malformed dates to Postmark. The API
     * expects YYYY-MM-DD for its date filters and returns a 422 on anything
     * else, so catching mistakes locally (US-style slashes, reversed
     * day/month order, calendar-invalid dates like 2026-02-30) saves a
     * round-trip and gives the caller a clearer error. `Carbon::hasFormat`
     * checks both the format and that PHP's date parser raised no warnings,
     * so it catches both shape and calendar issues in one call.
     */
    protected function assertYmdDate(?string $value, string $parameter): void
    {
        if ($value === null) {
            return;
        }

        if (! Carbon::hasFormat($value, 'Y-m-d')) {
            throw new InvalidArgumentException(sprintf(
                'Postmark %s must be a valid YYYY-MM-DD date, got "%s".',
                $parameter,
                $value,
            ));
        }
    }
}
