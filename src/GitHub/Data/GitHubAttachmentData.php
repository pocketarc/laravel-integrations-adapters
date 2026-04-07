<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Data;

use DOMDocument;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

use function Safe\preg_match;

class GitHubAttachmentData extends Data
{
    /**
     * @param  array{body_html: string, body_plain: string}|null  $original
     */
    public function __construct(
        public readonly string $plain_url,
        public readonly ?string $authenticated_url,
        public readonly ?string $alt_text = null,
        public readonly ?array $original = null,
    ) {}

    /**
     * Extract all attachments (images) from GitHub content.
     * Uses body_html for authenticated URLs and body for plain URLs.
     *
     * @param  Collection<int, string>|null  $filterUrls  URL prefixes to filter out (e.g. Zendesk URLs).
     * @return Collection<int, self>
     */
    public static function extractFromContent(string $bodyHtml, string $bodyPlain, ?Collection $filterUrls = null): Collection
    {
        /** @var Collection<int, self> $attachments */
        $attachments = collect();

        if ($bodyHtml === '') {
            return $attachments;
        }

        $dom = new DOMDocument;
        $previousUseErrors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$bodyHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        $images = $dom->getElementsByTagName('img');

        foreach ($images as $img) {
            $attachment = self::extractFromImage($img, $bodyHtml, $bodyPlain);
            if ($attachment !== null) {
                $attachments->push($attachment);
            }
        }

        if ($filterUrls !== null && $filterUrls->isNotEmpty()) {
            $attachments = $attachments
                ->reject(fn (self $a): bool => $filterUrls->contains(
                    fn (string $prefix): bool => str_starts_with($a->plain_url, $prefix),
                ))
                ->values();
        }

        /** @var Collection<int, self> */
        return $attachments;
    }

    private static function extractFromImage(\DOMElement $img, string $bodyHtml, string $bodyPlain): ?self
    {
        $canonicalSrc = $img->getAttribute('data-canonical-src');
        $authenticatedUrl = $canonicalSrc !== '' ? $canonicalSrc : $img->getAttribute('src');

        if ($authenticatedUrl === '') {
            return null;
        }

        $alt = $img->getAttribute('alt');
        $altText = $alt !== '' ? $alt : null;
        $original = [
            'body_html' => $bodyHtml,
            'body_plain' => $bodyPlain,
        ];

        $hashMatch = [];
        $matchResult = preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $authenticatedUrl, $hashMatch);

        $plainUrl = null;
        $resolvedAuthenticatedUrl = null;

        if ($matchResult === 1) {
            $hash = $hashMatch[1];
            $resolvedAuthenticatedUrl = $authenticatedUrl;

            $plainMatch = [];
            $plainMatchResult = preg_match('#https://[^\s"\']+'.preg_quote($hash, '#').'[^\s"\']*#i', $bodyPlain, $plainMatch);
            if ($plainMatchResult === 1) {
                $plainUrl = rtrim($plainMatch[0], '>,)');
            }
        }

        return new self(
            plain_url: $plainUrl ?? $authenticatedUrl,
            authenticated_url: $resolvedAuthenticatedUrl,
            alt_text: $altText,
            original: $original,
        );
    }
}
