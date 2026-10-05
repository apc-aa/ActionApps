<?php

declare(strict_types=1);

namespace AA\Expand;

use AA\Http\Client;
use AA\Http\ClientInterface;
use AA\Http\TransportException;
use AA\Util\Txt;

/**
 * What {include:<url>:<type>:<headers>:<body>:<err>} promises a template: the body of a 2xx answer,
 * otherwise $err with _#status / _#errmsg filled in ('' without $err).
 *
 * It lives next to the consumer rather than in AA\Http because this is the template's contract,
 * not the client's: redirects are followed and a body without a Content-Type goes as a form,
 * because PHP's http wrapper did both for the old AA_Http::request() and templates rely on it.
 *
 * Pinned by tests/Unit/Legacy/StringexpandIncludeTest.php before the move.
 */
final class HttpInclude
{
    private static ?ClientInterface $http = null;

    /** tests hand in a fake; null goes back to the real client */
    public static function useHttp(?ClientInterface $http): void
    {
        self::$http = $http;
    }

    /** a page render must not wait longer than this for somebody else's server */
    private const SECONDS = 30;
    /** {file2text:} pulls whole documents down this path, and a slow 40 MB pdf is not an error */
    public const DOWNLOAD_SECONDS = 300;

    /**
     * @param array<string, string> $headers
     * @param int $seconds ceiling for the whole call; 0 takes the one a page render can afford
     */
    public static function fetch(string $method, string $url, string $body = '', array $headers = [], string $err = '', int $seconds = 0): string
    {
        if ($body !== '' && !isset(array_change_key_case($headers, CASE_LOWER)['content-type'])) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        try {
            $answer = self::http($seconds)->request($method, $url, $body === '' ? null : $body, $headers);
        } catch (TransportException $e) {
            // no answer at all: there is no status to report, which is what the old path did too
            return self::failed($err, '', $e->getMessage());
        }

        // The status, and not a word of the remote body. _#errmsg is substituted into the page a
        // template author wrote, so anything from the other end is reflected XSS: a template that
        // passes URL_PARAMETERS on lets a visitor choose a parameter most APIs echo back in their
        // 400. The old path put PHP's own stream error here, never the body - this keeps to that.
        // The body is still worth seeing while debugging, so warn() gets it, escaped.
        return $answer->ok() ? $answer->body : self::failed($err, (string) $answer->status, 'HTTP ' . $answer->status, $answer->excerpt());
    }

    private static function http(int $seconds = 0): ClientInterface
    {
        // The old wrapper waited 60 s for every silent stretch and had no ceiling on the call at
        // all. A page render cannot afford that; a download of a document can, so it says so and
        // gets a client of its own rather than raising the ceiling for every template on the site.
        if (!is_null(self::$http)) {
            return self::$http;   // a test handed us one; it decides its own limits
        }
        if ($seconds > 0 && $seconds !== self::SECONDS) {
            return new Client(timeout: $seconds, redirects: 10);
        }

        return self::$http = new Client(timeout: self::SECONDS, redirects: 10);
    }

    /**
     * The template gets its own $err text with the placeholders filled in, and the editor gets the
     * reason - warn() is itself a no-op unless AA::$debug, so this is the debug line the old verb
     * branch had, now covering all five include paths instead of one.
     */
    private static function failed(string $err, string $status, string $message, string $body = ''): string
    {
        if (function_exists('warn')) {
            warn(Txt::safe('{include} failed: ' . $message . ($body === '' ? '' : ' - ' . $body)));
        }

        return str_replace(['_#status', '_#errmsg'], [$status, $message], $err);
    }
}
