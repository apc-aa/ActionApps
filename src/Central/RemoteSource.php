<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Http\Client;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException;
use AA_Request;
use AA_Response;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
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
    /** the clocks of the two AAs may differ a little */
    private const CLOCK_SLACK = 300;
    /** seconds for one answer; a batch of 500 items takes the source a few */
    public const TIMEOUT = 120;
    /** the token the target keeps for a run: its batches run in a day, Continue logs in again */
    public const TOKEN_TTL = 24 * 3600;

    /** nesting of an answer; the module definitions are some five levels deep */
    private const MAX_DEPTH = 64;

    /** @param ?HttpClientInterface $transport the connection under AA\Http\Client; another one for tests only */
    public function __construct(
        private readonly string $url,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $timeout = self::TIMEOUT,
        private readonly ?HttpClientInterface $transport = null,
    ) {}

    /** the responder of the AA of the central_conf row
     *  @throws SourceFailed no such row */
    public static function forAa(int $aa_id): self
    {
        $aa = Actionapps::find($aa_id) ?? throw new SourceFailed(SourceFailed::UNREACHABLE, 'No such AA in the Central list');
        return new self($aa->getComunicatorUrl());
    }

    /** the responder of a remote source of an install run (InstallRun::source()): the store or a
     *  central_conf row - never an address of the request
     *  @throws SourceFailed anything else, or no such row */
    public static function forSource(string $source): self
    {
        return match (true) {
            $source === InstallRun::STORE                    => new self(AppStore::responderUrl()),
            (bool) preg_match('/^[1-9]\d{0,9}\z/', $source) => self::forAa((int) $source),
            default                                           => throw new SourceFailed(SourceFailed::UNREACHABLE, 'No such remote source'),
        };
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
        $claims = self::claims($token);
        return (($claims['scp'] ?? null) === 'apps') && self::livesADay($claims);
    }

    /** the claims of a JWT, not verified - only its issuer can check the signature; null when unreadable
     *  @return ?array<string, mixed> */
    public static function claims(string $token): ?array
    {
        $claims = json_decode((string) base64_decode(strtr(explode('.', $token . '..')[1], '-_', '+/'), true), true);
        return is_array($claims) ? $claims : null;
    }

    /** issued for a day at most - an older AA ignores ttl and gives a year
     *  @param ?array<string, mixed> $claims */
    public static function livesADay(?array $claims): bool
    {
        return is_int($claims['iat'] ?? null) && is_int($claims['exp'] ?? null) && ($claims['exp'] - $claims['iat'] <= self::TOKEN_TTL + self::CLOCK_SLACK);
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
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        if ($token !== '') {
            $headers['Authorization'] = "Bearer $token";
        }
        $http  = new Client(timeout: $this->timeout, idle: $this->timeout, http: $this->transport, maxBytes: $limit);
        $start = microtime(true);
        try {
            $answer = $http->request('POST', $this->url, http_build_query(['request' => serialize($request)] + $post), $headers);
        } catch (ResponseTooLargeException) {
            throw new SourceFailed(SourceFailed::FAILED, 'The answer of the remote AA is too big');
        } catch (TransportException $e) {
            // curl reports the whole-request limit as a plain transport error, not as a timeout
            if (($e->getPrevious() instanceof TimeoutExceptionInterface) || (microtime(true) - $start >= $this->timeout)) {
                throw new SourceFailed(SourceFailed::UNREACHABLE, 'The remote AA did not answer in time');
            }
            // the page gets no detail; the log no login a URL may carry (user:password@)
            error_log('AA Central: the remote AA cannot be reached: ' . preg_replace(['~//[^/@\s]*@~', '/[\x00-\x1F\x7F]+/'], ['//', ' '], $e->getMessage()));
            throw new SourceFailed(SourceFailed::UNREACHABLE, 'The remote AA cannot be reached - the details are in the PHP error log');
        }
        if ($answer->status !== 200) {
            throw new SourceFailed(SourceFailed::UNREACHABLE, (($answer->status >= 300) && ($answer->status < 400))
                ? "The remote AA redirects (HTTP {$answer->status}) - its row in the Central list must name the final address"
                : "The remote AA answered HTTP {$answer->status}");
        }
        return self::response($answer->body, $classes);
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
}
