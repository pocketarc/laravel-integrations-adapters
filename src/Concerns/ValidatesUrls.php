<?php

declare(strict_types=1);

namespace Integrations\Adapters\Concerns;

use RuntimeException;

use function Safe\dns_get_record;
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

        $ips = self::resolveHostIps($host);
        if ($ips === []) {
            throw new RuntimeException("Cannot resolve hostname: {$host}");
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('URL host resolves to a blocked IP range.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function resolveHostIps(string $host): array
    {
        $ips = self::extractIps(dns_get_record($host, DNS_A), 'ip');

        return [...$ips, ...self::extractIps(dns_get_record($host, DNS_AAAA), 'ipv6')];
    }

    /**
     * @param  list<mixed>  $records
     * @return list<string>
     */
    private static function extractIps(array $records, string $key): array
    {
        $ips = [];

        foreach ($records as $record) {
            if (is_array($record) && array_key_exists($key, $record) && is_string($record[$key])) {
                $ips[] = $record[$key];
            }
        }

        return $ips;
    }
}
