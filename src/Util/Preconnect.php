<?php

declare(strict_types=1);

namespace AA\Util;

use AA\Cache\ImgCache;

/**
 * The Link header asking the browser to connect early to the origins a page needs late: the
 * connection (DNS, TCP, TLS) opens as the headers arrive, not when the browser gets to the first
 * file there. A header, so templates need no change, and the page cache keeps it with the page.
 */
final class Preconnect
{
    /** Google Fonts: the stylesheet is linked in the head, the fonts come from this origin only
     *  once the stylesheet has arrived. Fonts load in CORS mode, hence crossorigin. */
    private const GOOGLE_FONTS = '<https://fonts.gstatic.com>; rel=preconnect; crossorigin';

    /**
     * "Link: <origin>; rel=preconnect, ..." for an HTML $page, '' when it needs none: the host of
     * IMG_UPLOAD_URL when the page links uploads there absolutely (the main image of a page on
     * another host showed 0.1-0.7 s sooner on a slow mobile; on that host itself the hint costs
     * nothing), and the font origin of Google Fonts.
     *
     * @param array<string, string> $headers header lines the page goes with, see AA::getHeaders()
     */
    public static function header(array $headers, string $page): string
    {
        if (!str_starts_with($headers['type'] ?? '', 'Content-Type: text/html;') || isset($headers['disposition'])) {
            return '';
        }
        $links  = [];
        $origin = self::uploadOrigin();
        if (($origin !== '') && str_contains($page, strstr($origin, '//') . '/' . ImgCache::srcBase())) {
            $links[] = "<$origin>; rel=preconnect";
        }
        if (str_contains($page, '//fonts.googleapis.com/')) {
            $links[] = self::GOOGLE_FONTS;
        }
        return ($links === []) ? '' : 'Link: ' . implode(', ', $links);
    }

    /** origin of IMG_UPLOAD_URL ("https://up.example.org"), '' when it names no host */
    private static function uploadOrigin(): string
    {
        $url = ImgCache::configured() ? parse_url(ImgCache::uploadUrl()) : false;
        if (!\is_array($url) || empty($url['host']) || !\in_array($url['scheme'] ?? '', ['http', 'https'], true)) {
            return '';
        }
        return "$url[scheme]://$url[host]" . (isset($url['port']) ? ":$url[port]" : '');
    }
}
