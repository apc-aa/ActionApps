<?php

declare(strict_types=1);

namespace AA\Central;

use AA_Request;
use AA_Response;
use Closure;
use Throwable;

/** The client Central talks to another AA with - to its central/responder.php. A request carries a
 *  superadmin's token or password, an answer may carry password hashes, so: https only, no redirect followed
 *  (it would take the token elsewhere), the peer's certificate verified, the answer cut off while it is
 *  read, a timeout, and only an AA_Response of plain data accepted. The URL comes only from a central_conf
 *  row. */
final class RemoteSource
{
    /** a package is at most 8 MB of XML (XmlGuard) - with the serialized envelope around it */
    public const MAX_BYTES = 16 * 1024 * 1024;
    /** any other answer - a token, counts, logins, a module list */
    public const SMALL_BYTES = 1024 * 1024;
    /** an answer has a handful of header lines - many more is no AA */
    private const MAX_HEADERS = 64;
    /** the clocks of the two AAs may differ a little */
    private const CLOCK_SLACK = 300;
    /** seconds for one answer; a batch of 500 items takes the source a few */
    public const TIMEOUT = 120;
    /** the token the target keeps for a run: its batches run in a day, Continue logs in again */
    public const TOKEN_TTL = 24 * 3600;

    private const CHUNK = 65536;
    /** nesting of an answer; the module definitions are some five levels deep */
    private const MAX_DEPTH = 64;

    /** @var Closure(string, resource): array{resource, list<string>} */
    private readonly Closure $open;

    /**
     * @param (Closure(string, resource): array{resource, list<string>})|null $open opens the connection:
     *        (url, stream context) => [stream, response header lines]; another one for tests only
     */
    public function __construct(
        private readonly string $url,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $timeout = self::TIMEOUT,
        ?Closure $open = null,
    ) {
        $this->open = $open ?? self::connect(...);
    }

    /** the responder of the AA of the central_conf row
     *  @throws SourceFailed no such row */
    public static function forAa(int $aa_id): self
    {
        $aa = Actionapps::find($aa_id) ?? throw new SourceFailed(SourceFailed::UNREACHABLE, 'No such AA in the Central list');
        return new self($aa->getComunicatorUrl());
    }

    /** Logs in to the source by the username and password (+ 2FA code) of this one request and returns a
     *  token of the scope apps for TOKEN_TTL. The source gives it only to its superadmin.
     *  @throws SourceFailed */
    public function jwt(string $username, string $password, string $code2fa = ''): string
    {
        $response = $this->ask(new AA_Request('Get_Jwt', ['ttl' => self::TOKEN_TTL, 'scope' => 'apps']), ['username' => $username, 'password' => $password, 'code2fa' => $code2fa]);
        $token    = $response->getResponse();
        if ($response->isError() || !is_string($token) || !self::isToken($token)) {
            throw new SourceFailed(SourceFailed::LOGIN, 'The source AA refused the login - a superadmin of it has to log in (error ' . $response->getError() . ')');
        }
        if (!self::shortAppsToken($token)) {
            throw new SourceFailed(SourceFailed::FAILED, 'The source AA gave a token for more than apps or for longer than a day - update the source AA');
        }
        return $token;
    }

    /** What the claims of the token say - the signature is the source's, this AA cannot check it; it only
     *  refuses to keep a token that could do more, or longer, than it asked for (an older source ignores
     *  ttl and scope and would give its year-long admin token). */
    private static function shortAppsToken(string $token): bool
    {
        $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), true), true);
        return is_array($claims) && (($claims['scp'] ?? null) === 'apps') && is_int($claims['iat'] ?? null) && is_int($claims['exp'] ?? null)
            && ($claims['exp'] - $claims['iat'] <= self::TOKEN_TTL + self::CLOCK_SLACK);
    }

    /** The token with the address it was given for - what a run keeps, so that a Central row changed
     *  meanwhile cannot send the token to another server. */
    public function seal(string $token): string
    {
        return json_encode(['u' => $this->url, 't' => $token], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);   // a row of no UTF-8 gets a token unseal() refuses
    }

    /** the token of seal() when it was given for this address; '' otherwise - the source refuses the
     *  request then and Continue asks for a new login */
    public function unseal(string $sealed): string
    {
        $data = json_decode($sealed, true);
        return (is_array($data) && (($data['u'] ?? null) === $this->url) && is_string($data['t'] ?? null)) ? $data['t'] : '';
    }

    /** @param array<string, string> $post more POST fields (the login of jwt()) @param string $token Bearer, '' = none
     *  @param int $maxBytes the answer at most - MAX_BYTES only for a package
     *  @param list<class-string> $classes objects the answer may hold besides plain data (the module definitions of Central)
     *  @throws SourceFailed the AA cannot be asked safely or gave no plain AA_Response */
    public function ask(AA_Request $request, array $post = [], string $token = '', int $maxBytes = self::SMALL_BYTES, array $classes = []): AA_Response
    {
        $limit = min($maxBytes, $this->maxBytes);
        if (strncasecmp($this->url, 'https://', 8) !== 0) {
            throw new SourceFailed(SourceFailed::UNREACHABLE, 'The remote AA is not on https - its row in the Central list must use https');
        }
        if (($token !== '') && !self::isToken($token)) {
            throw new SourceFailed(SourceFailed::LOGIN, 'The login token is no token');       // a header line of its own
        }
        $content = http_build_query(['request' => serialize($request)] + $post);
        $context = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'header'          => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($content) . "\r\n" . (($token === '') ? '' : "Authorization: Bearer $token\r\n"),
                'content'         => $content,
                'follow_location' => 0,
                'timeout'         => (float) $this->timeout,
                'ignore_errors'   => true,          // the status is checked below, for every code alike
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        [$stream, $headers] = ($this->open)($this->url, $context);
        try {
            $this->status($headers, $limit);
            $body = $this->read($stream, $limit);
        } finally {
            fclose($stream);
        }
        return self::response($body, $classes);
    }

    /** @param list<string> $headers @throws SourceFailed */
    private function status(array $headers, int $limit): void
    {
        if (count($headers) > self::MAX_HEADERS) {
            throw new SourceFailed(SourceFailed::FAILED, 'The remote AA answered with too many header lines');
        }
        $status = 0;
        $length = null;
        foreach ($headers as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                [$status, $length] = [(int) $m[1], null];          // the last response counts
            } elseif (preg_match('/^Content-Length:\s*(\d+)/i', $line, $m)) {
                $length = (int) $m[1];
            }
        }
        if ($status !== 200) {
            throw new SourceFailed(SourceFailed::UNREACHABLE, (($status >= 300) && ($status < 400))
                ? "The remote AA redirects (HTTP $status) - its row in the Central list must name the final address"
                : "The remote AA answered HTTP $status");
        }
        if (($length !== null) && ($length > $limit)) {
            throw new SourceFailed(SourceFailed::FAILED, 'The answer of the remote AA is too big');
        }
    }

    /** @param resource $stream @throws SourceFailed */
    private function read($stream, int $limit): string
    {
        stream_set_timeout($stream, $this->timeout);
        $deadline = microtime(true) + $this->timeout;
        $body     = '';
        while (!feof($stream)) {
            $chunk = fread($stream, self::CHUNK);
            if ($chunk === false) {
                throw new SourceFailed(SourceFailed::UNREACHABLE, 'The answer of the remote AA broke off');
            }
            $body .= $chunk;
            if (strlen($body) > $limit) {
                throw new SourceFailed(SourceFailed::FAILED, 'The answer of the remote AA is too big');
            }
            if ((microtime(true) > $deadline) || (($chunk === '') && stream_get_meta_data($stream)['timed_out'])) {
                throw new SourceFailed(SourceFailed::UNREACHABLE, 'The remote AA did not answer in time');
            }
        }
        return $body;
    }

    /** @param list<class-string> $classes @throws SourceFailed */
    private static function response(string $body, array $classes): AA_Response
    {
        try {
            $response = @unserialize(trim($body), ['allowed_classes' => [AA_Response::class, ...$classes], 'max_depth' => self::MAX_DEPTH]);
        } catch (Throwable) {                       // e.g. a property of the wrong type
            $response = null;
        }
        $budget = strlen($body);                    // each value takes some bytes of the answer
        if (!($response instanceof AA_Response) || !self::plain($response->getResponse(), $classes, $budget)) {
            throw new SourceFailed(SourceFailed::FAILED, 'The remote AA gave no answer of plain data - is it an AA new enough?');
        }
        return $response;
    }

    /** no object anywhere but of $classes, and those of plain data inside - a class the target does not
     *  know becomes __PHP_Incomplete_Class
     *  @param list<class-string> $classes
     *  @param int $budget values left to visit: references (R:) could make a short answer visit forever */
    private static function plain(mixed $value, array $classes, int &$budget, int $depth = 0): bool
    {
        if ((--$budget < 0) || ($depth > self::MAX_DEPTH)) {
            return false;
        }
        if (is_object($value)) {
            if (!in_array(get_class($value), $classes, true)) {
                return false;
            }
            $value = (array) $value;               // the protected and private properties too
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if (!self::plain($v, $classes, $budget, $depth + 1)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** the form of a JWT - it goes to a header line of its own */
    public static function isToken(string $token): bool
    {
        return (bool) preg_match('/^[\w-]+\.[\w-]+\.[\w-]+\z/', $token);
    }

    /** @param resource $context @return array{resource, list<string>} @throws SourceFailed */
    private static function connect(string $url, $context): array
    {
        error_clear_last();                  // the log tells this failure, not an older one
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            // the page gets no detail; the log no login a URL may carry (user:password@)
            error_log('AA Central: the remote AA cannot be reached: ' . preg_replace(['~//[^/@\s]*@~', '/[\x00-\x1F\x7F]+/'], ['//', ' '], (string) (error_get_last()['message'] ?? '')));
            throw new SourceFailed(SourceFailed::UNREACHABLE, 'The remote AA cannot be reached - the details are in the PHP error log');
        }
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        return [$stream, is_array($headers) ? array_values(array_map('strval', array_slice($headers, 0, self::MAX_HEADERS + 1))) : []];
    }
}
