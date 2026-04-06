<?php

declare(strict_types=1);

namespace Integrations\Adapters\Concerns;

use RuntimeException;

use function Safe\parse_url;

trait ValidatesUrls
{
    /**
     * Reject URLs that resolve to private, loopback, or link-local IP ranges.
     * Prevents SSRF attacks targeting cloud metadata endpoints or internal services.
     */
    protected static function assertUrlNotPrivate(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new RuntimeException('Cannot parse host from URL.');
        }

        $ips = gethostbynamel($host);
        if ($ips === false || $ips === []) {
            throw new RuntimeException("Cannot resolve hostname: {$host}");
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('URL host resolves to a blocked IP range.');
            }
        }
    }
}
