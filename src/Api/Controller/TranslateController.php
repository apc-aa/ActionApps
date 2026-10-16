<?php

declare(strict_types=1);

/**
 * Translates text for the item editor: the browser posts here and AA calls the
 * service, so no page talks to a third party itself.
 *
 * The browser cannot do it — over GET the text hits a ~16 kB URL limit (≈8 000
 * characters of Czech) and error responses carry no CORS header, so a failure
 * reaches the page as an opaque TypeError.
 *
 * Needs a logged-in user AND same-host: editing items is admin work, unlike
 * SpeechController's anonymous dictation.
 *
 * @package Api
 */

namespace AA\Api\Controller;

use AA\Api\JsonResponse;
use AA\Api\LoggedInGate;
use AA\Api\SameHostGate;
use AA\Http\Client;
use AA\Http\ClientInterface;
use AA\Http\TransportException;

class TranslateController
{
    use LoggedInGate;
    use SameHostGate;
    /** @param ?ClientInterface $http tests hand in a fake; api/index.php uses new TranslateController() */
    public function __construct(private ?ClientInterface $http = null) {}

    protected function http(): ClientInterface
    {
        // AA's own name, like everything else AA fetches. Tried live on 21 Sep 2026: the keyless
        // endpoint answers 200 to it and to no User-Agent at all (what the old curl call sent), but
        // 302 to a blank one - so do not "fix" this by blanking the header. Should translations
        // start failing with a 403 or a 3xx, a filter on this name is the first thing to suspect.
        return $this->http ??= new Client();
    }

    /** Free, keyless, undocumented Google endpoint. No terms, no guarantee. */
    protected const ENDPOINT = 'https://translate.googleapis.com/translate_a/single';

    /** Reported back so the caller can tell which service produced the text. */
    protected const PROVIDER = 'google-gtx';

    /** Cap on one request. Ten times any real AA field, and low enough that even
     *  a slow request stays near PHP's max_execution_time. */
    public const MAX_CHARS = 100000;

    /** Source bytes per provider call. Measured: 40 kB answers in 0.4-2.3 s and
     *  46 kB still works, so a whole article goes in one round trip. */
    protected const CHUNK_BYTES = 40000;

    /** Retries after a 429/503, i.e. the service throttling us. 0 because every
     *  install now calls from one server IP - retrying while throttled adds load. */
    protected const RETRIES       = 0;
    /** First back-off in µs; each further attempt waits twice as long. */
    protected const RETRY_BASE_US = 400000;

    public function translate(): JsonResponse
    {
        if (!$this->sameHostRequest()) {
            return JsonResponse::error(401, 'Unauthorized');
        }
        if (!$this->isLoggedIn()) {
            // The editor reads this on the button, so name the real cause.
            return JsonResponse::error(401, 'Not logged in - the editing session may have expired');
        }

        $from = $this->sanitizeLang($_POST['from'] ?? null);
        $to   = $this->sanitizeLang($_POST['to'] ?? null);
        if ($from === null || $to === null) {
            return JsonResponse::error(400, 'Missing or invalid language code');
        }

        $text = $_POST['text'] ?? '';
        if (!is_string($text) || trim($text) === '') {
            // Never 200 with an empty string: the caller writes it into the target
            // field and would wipe it.
            return JsonResponse::error(400, 'Nothing to translate');
        }
        if (mb_strlen($text) > static::MAX_CHARS) {
            return JsonResponse::error(413, 'Text exceeds the ' . static::MAX_CHARS . ' character limit');
        }
        if ($from === $to) {
            return JsonResponse::ok(['text' => $text, 'provider' => static::PROVIDER]);
        }

        $out = '';
        foreach ($this->splitChunks($text, static::CHUNK_BYTES) as $chunk) {
            if (trim($chunk) === '') {
                $out .= $chunk;    // nothing to translate - keep it as it is
                continue;
            }
            $result = $this->callProvider($chunk, $from, $to);
            if (!$result['ok']) {
                return JsonResponse::error($result['status'], $result['message']);
            }
            if ($result['text'] === '') {
                // Per chunk, not on the total: an empty later chunk would otherwise
                // vanish into a 200 with silently truncated text.
                return JsonResponse::error(502, 'Translation service returned no text');
            }
            $out .= $result['text'];
        }
        return JsonResponse::ok(['text' => $out, 'provider' => static::PROVIDER]);
    }

    /** Accept only "xx"/"xxx" with an optional "-YY"/"-Yyyy" subtag; anything else
     *  is dropped, so nothing from the request reaches the provider URL. */
    protected function sanitizeLang(mixed $lang): ?string
    {
        if (!is_string($lang) || $lang === '') {
            return null;
        }
        return preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $lang) ? $lang : null;
    }

    /**
     * Split $text into pieces of at most $limit bytes. Cuts only at a paragraph
     * break, a line break or a space outside a tag - all ASCII, so a cut never
     * lands inside a UTF-8 character or inside markup.
     *
     * @return string[] chunks that concatenate back to $text
     */
    protected function splitChunks(string $text, int $limit): array
    {
        $chunks = [];
        while (strlen($text) > $limit) {
            $cut = $this->lastBoundary(substr($text, 0, $limit));
            if ($cut <= 0) {
                $cut = $this->hardCut($text, $limit);
            }
            $chunks[] = substr($text, 0, $cut);
            $text     = substr($text, $cut);
        }
        if ($text !== '') {
            $chunks[] = $text;
        }
        return $chunks;
    }

    /** Byte offset just past the last safe split point in $head; 0 when there is none. */
    protected function lastBoundary(string $head): int
    {
        foreach (["\n\n", "\n"] as $sep) {
            $pos = strrpos($head, $sep);
            if ($pos !== false) {
                return $pos + strlen($sep);
            }
        }
        // Walk back to a space that is not between "<" and ">", so a tag stays whole.
        $pos = strlen($head);
        while (($pos = strrpos(substr($head, 0, $pos), ' ')) !== false) {
            if (!$this->endsInsideTag(substr($head, 0, $pos))) {
                return $pos + 1;
            }
        }
        return 0;
    }

    /** No boundary found: cut at $limit, but never inside a UTF-8 character or a tag. */
    protected function hardCut(string $text, int $limit): int
    {
        $cut = $limit;
        while ($cut > 1 && (ord($text[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        $head = substr($text, 0, $cut);
        if ($this->endsInsideTag($head)) {
            $open = strrpos($head, '<');
            if ($open !== false && $open > 0) {
                return $open;          // cut in front of the unfinished tag
            }
            // Tag starts at 0 and does not close within the limit (a base64 src can
            // be tens of kB): overshoot past its ">" rather than tear it in half.
            $close = strpos($text, '>', $cut);
            if ($close !== false) {
                return $close + 1;
            }
        }
        return $cut;
    }

    /** True when $piece stops between a "<" and its ">". */
    protected function endsInsideTag(string $piece): bool
    {
        $open = strrpos($piece, '<');
        if ($open === false) {
            return false;
        }
        $close = strrpos($piece, '>');
        return $close === false || $close < $open;
    }

    /**
     * POST one chunk to the translation service. Split out so unit tests can
     * override it without hitting the network.
     *
     * @return array{ok:true,text:string}|array{ok:false,status:int,message:string}
     */
    protected function callProvider(string $text, string $from, string $to): array
    {
        $url = static::ENDPOINT . '?' . http_build_query([
            'client' => 'gtx',
            'sl'     => $from,
            'tl'     => $to,
            'dt'     => 't',
        ]);

        for ($attempt = 0; ; $attempt++) {
            try {
                // POST, not GET: the query-string form caps out at ~16 kB of URL.
                $answer = $this->http()->request('POST', $url, http_build_query(['q' => $text]), ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8']);
            } catch (TransportException $e) {
                // The transport message can name an internal proxy, so log it, don't show it.
                error_log('[aa-translate] request failed: ' . $e->getMessage());
                return ['ok' => false, 'status' => 502, 'message' => 'Translation service is unreachable'];
            }
            $status = $answer->status;

            // 429/503 is the free endpoint throttling a burst of clicks — back off.
            if (($status === 429 || $status === 503) && $attempt < static::RETRIES) {
                usleep(static::RETRY_BASE_US * (2 ** $attempt));
                continue;
            }
            if ($status >= 400) {
                // Logged so throttling of this server's IP is visible somewhere other
                // than one editor's screen. Grep the error log for "[aa-translate]".
                error_log('[aa-translate] provider returned HTTP ' . $status . ' after ' . ($attempt + 1) . ' attempt(s)');
                return ['ok' => false, 'status' => 502, 'message' => 'Translation service returned HTTP ' . $status];
            }
            return $this->parseResponse($answer->body);
        }
    }

    /**
     * The service answers with a nested array whose first element is the list of
     * translated segments: [[[translated, original, …], …], …].
     *
     * @return array{ok:true,text:string}|array{ok:false,status:int,message:string}
     */
    protected function parseResponse(string $body): array
    {
        $payload = json_decode($body, true);
        if (!is_array($payload) || !isset($payload[0]) || !is_array($payload[0])) {
            return ['ok' => false, 'status' => 502, 'message' => 'Translation service returned an unexpected response'];
        }
        $text = '';
        foreach ($payload[0] as $segment) {
            if (is_array($segment) && is_string($segment[0] ?? null)) {
                $text .= $segment[0];
            }
        }
        return ['ok' => true, 'text' => $text];
    }
}
