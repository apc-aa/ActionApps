<?php

declare(strict_types=1);

namespace AA\Import;

use AA\Http\Client;
use AA\Http\ClientInterface;
use AA\Http\Response;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException;
use AA\Import\Credential\Secret;
use AA\Import\Credential\Store;
use AA\IO\SourceFailure;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What a source gets from the run for one step: the only way out to the network, its keys and
 * the end of the step. Every exception of http() is SourceRefused or SourceUnavailable whose
 * message is AA's own text with the requested URL masked - the library's text carries the URL
 * re-encoded, and a key in it would slip past redact().
 */
final class Context
{
    public const DEFAULT_MAX_BYTES = 20 * 1024 * 1024;

    /** an XML answer is parsed whole in memory */
    public const MAX_BYTES_CAP = 100 * 1024 * 1024;

    private const REDIRECTS = 3;

    private const IDLE_SECONDS = 10;

    /**
     * How NoPrivateNetworkHttpClient words a refused address - it has no own exception class. If
     * Symfony changes the text, the address stays blocked; only the code becomes TRANSPORT.
     */
    private const BLOCKED = '/^(?:Host|IP) "([^"]*)" is blocked for "/';

    private readonly int $maxBytes;

    /** @var array<string, array{string, string}> "vendor\0context" => [vendor, context] the source declared */
    private array $declared = [];

    /**
     * Keys read so far - only when needed, so a damaged one fails the source that uses it, not
     * every step. false = damaged in the configuration.
     * @var array<string, Secret|false|null>
     */
    private array $secrets = [];

    private ?HttpClientInterface $guarded = null;

    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolver;

    /**
     * @param list<array{vendor: string, context: string}> $credentials from SourceInterface::credentials()
     * @param float $deadline microtime of the end of the step
     * @param ?HttpClientInterface $transport the connection under the guard; another one for tests only
     * @param ?\Closure(string): list<string> $resolver IP addresses of a host name; another one for tests only
     */
    public function __construct(array $credentials, private readonly float $deadline, int $maxBytes = self::DEFAULT_MAX_BYTES, private readonly ?HttpClientInterface $transport = null, ?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::lookup(...);
        // 0 means "no limit" to Client
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('maxBytes must be positive');
        }
        $this->maxBytes = min($maxBytes, self::MAX_BYTES_CAP);
        foreach ($credentials as $credential) {
            $this->declared[$credential['vendor'] . "\0" . $credential['context']] = [$credential['vendor'], $credential['context']];
        }
    }

    /**
     * HTTP(S) only to public addresses, also after a redirect, at most 3 redirects; the request
     * ends with the step, and after the deadline none starts. The port is not restricted: the
     * source takes its URL from its settings. A non-2xx status is an answer - what it means is up
     * to the caller. DNS is not limited by the deadline: neither the guard's lookup nor the second
     * one that sorts a blocked host, so a failing resolver can take up to about twice its timeout.
     */
    public function http(): ClientInterface
    {
        return new class ($this->send(...)) implements ClientInterface {
            /** @param \Closure(string, string, ?string, array<string, string>): Response $send */
            public function __construct(private readonly \Closure $send) {}

            public function request(string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] ?string $body = null, #[SensitiveParameter] array $headers = []): Response
            {
                return ($this->send)($method, $url, $body, $headers);
            }
        };
    }

    /**
     * null when the installation has not got the key; asking for an undeclared key is a bug of the source
     * @throws SourceRefused NO_CREDENTIAL when the key is damaged in the configuration
     */
    public function secret(string $vendor, string $context = ''): ?Secret
    {
        $id = $vendor . "\0" . $context;
        if (!array_key_exists($id, $this->declared)) {
            throw new LogicException("The source did not declare the key $vendor/$context in credentials()");
        }
        $secret = $this->read($id);
        if ($secret === false) {
            throw new SourceRefused('NO_CREDENTIAL', "The key $vendor/$context is damaged in the configuration");
        }
        return $secret;
    }

    /** $text with every declared key, plain or URL-encoded, replaced by *** */
    public function redact(#[SensitiveParameter] string $text): string
    {
        $values = [];
        foreach (array_keys($this->declared) as $id) {
            // a damaged key is never handed out, so it cannot be in the text
            $secret = $this->read($id);
            if (($secret instanceof Secret) && !$secret->isEmpty()) {
                $value    = $secret->reveal();
                $values[] = $value;
                $values[] = urlencode($value);
                $values[] = rawurlencode($value);
            }
        }
        $values = array_unique($values);
        // an encoded form may contain the plain value; the longer one goes first
        usort($values, fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        return str_replace($values, '***', $text);
    }

    public function deadline(): float
    {
        return $this->deadline;
    }

    private function read(string $id): Secret|false|null
    {
        if (!array_key_exists($id, $this->secrets)) {
            try {
                $this->secrets[$id] = Store::resolve(...$this->declared[$id]);
            } catch (\UnexpectedValueException) {
                $this->secrets[$id] = false;
            }
        }
        return $this->secrets[$id];
    }

    /** @param array<string, string> $headers */
    private function send(string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] ?string $body, #[SensitiveParameter] array $headers): Response
    {
        // the guard checks this too; kept here so the rule does not depend on the library
        if (preg_match('~^https?://~i', $url) !== 1) {
            throw $this->failure(false, 'Only http(s) addresses are imported', $url);
        }
        $left = $this->deadline - microtime(true);
        if ($left <= 0) {
            throw $this->failure(true, 'The step ran out of time before the request', $url);
        }
        $seconds = max(1, (int) floor($left));
        $this->guarded ??= new NoPrivateNetworkHttpClient($this->transport ?? HttpClient::create());
        $client = new Client(timeout: $seconds, idle: min(self::IDLE_SECONDS, $seconds), agent: Client::agent('import'), redirects: self::REDIRECTS, http: $this->guarded, maxBytes: $this->maxBytes);

        try {
            $answer = $client->request($method, $url, $body, $headers);
        } catch (ResponseTooLargeException) {
            throw $this->failure(false, "The answer is over {$this->maxBytes} bytes", $url);
        } catch (TransportException $e) {
            throw $this->transportFailure($e, $url);
        }
        // the guard hands over the last 3xx when redirects ran out
        if ($answer->redirect !== '') {
            throw $this->failure(false, 'More than ' . self::REDIRECTS . ' redirects', $url);
        }
        return $answer;
    }

    /** Sorts a failed request into "ask again later" and "refused for good" */
    private function transportFailure(#[SensitiveParameter] TransportException $e, #[SensitiveParameter] string $url): SourceFailure
    {
        $texts = [];
        for ($cause = $e->getPrevious(); !is_null($cause); $cause = $cause->getPrevious()) {
            // a malformed URL, or a redirect to another scheme (the guard fails with a TypeError on file://)
            if (($cause instanceof \InvalidArgumentException) || ($cause instanceof \TypeError)) {
                return $this->failure(false, 'Only well-formed http(s) addresses are imported', $url);
            }
            $texts[] = $cause->getMessage();
        }
        foreach ($texts as $text) {
            if (preg_match(self::BLOCKED, $text, $m) === 1) {
                // the guard reports a host it failed to look up as blocked too; ask again
                return $this->blockedHost($m[1])
                    ? $this->failure(false, 'Address in a local or private network', $url)
                    : $this->failure(true, 'Host name not found', $url);
            }
        }
        $text = implode(' ', $texts);
        return match (true) {
            preg_match('/timeout|timed out|max duration/i', $text) === 1     => $this->failure(true, 'No answer in time', $url),
            preg_match('/resolve host|getaddrinfo|name or service/i', $text) === 1 => $this->failure(true, 'Host name not found', $url),
            default                                                         => $this->failure(true, 'No answer', $url),
        };
    }

    /** whether the host is, or resolves to, an address the guard blocks; a failed lookup is not */
    private function blockedHost(string $host): bool
    {
        $host = trim($host, '[]');
        $ips  = (filter_var($host, FILTER_VALIDATE_IP) !== false) ? [$host] : ($this->resolver)($host);
        foreach ($ips as $ip) {
            // the guard's own rule: private and reserved ranges by PHP, plus Symfony's list
            if ((filter_var($ip, FILTER_VALIDATE_IP) !== false)
                && ((filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) || IpUtils::checkIp($ip, IpUtils::PRIVATE_SUBNETS))) {
                return true;
            }
        }
        return false;
    }

    /**
     * IPv4 and IPv6 addresses of a host the way the guard looks them up, including the hosts file.
     * @return list<string>
     */
    private static function lookup(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $v6  = [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $v6[] = (string) ($record['ipv6'] ?? '');
        }
        // dns_get_record() asks DNS only; getaddrinfo also reads the hosts file
        if (($v6 === []) && function_exists('socket_addrinfo_lookup')) {
            foreach (socket_addrinfo_lookup($host, null, ['ai_socktype' => SOCK_STREAM, 'ai_family' => AF_INET6]) ?: [] as $info) {
                $v6[] = (string) (socket_addrinfo_explain($info)['ai_addr']['sin6_addr'] ?? '');
            }
        }
        return array_values(array_unique(array_filter([...$ips, ...$v6], fn(string $ip): bool => $ip !== '')));
    }

    /**
     * $text with control characters (C0, DEL, C1) and bytes of invalid UTF-8 percent-encoded: a line
     * break would split a log line, invalid UTF-8 makes json_encode() fail.
     */
    private static function printable(#[SensitiveParameter] string $text): string
    {
        // bytes, not /u: the pattern must not fail on invalid UTF-8; group 1 = a valid printable sequence
        $pattern = '/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]|([\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|[\x80-\xFF]/';

        return preg_replace_callback($pattern, fn(array $m): string => (($m[1] ?? '') !== '') ? $m[0] : rawurlencode($m[0]), $text) ?? '';
    }

    /** A failure with AA's own text and the requested URL masked */
    private function failure(bool $retry, string $what, #[SensitiveParameter] string $url): SourceFailure
    {
        $message = $what . ': ' . self::printable($this->redact($url));
        return $retry ? new SourceUnavailable('TRANSPORT', $message) : new SourceRefused('SOURCE', $message);
    }
}
