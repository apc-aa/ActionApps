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
use AA\Later\BatchBudget;
use AA\Later\BatchBudgetSpent;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What a source gets from the run for one step: the only way out to the network, its keys and
 * the end of the step. Every exception of http() but BatchBudgetSpent is SourceRefused or
 * SourceUnavailable whose message is AA's own text with the requested URL masked - the library's
 * text carries the URL re-encoded, and a key in it would slip past redact().
 */
final class Context
{
    public const DEFAULT_MAX_BYTES = 20 * 1024 * 1024;

    /** an XML answer is parsed whole in memory */
    public const MAX_BYTES_CAP = 100 * 1024 * 1024;

    private const REDIRECTS = 3;

    private const IDLE_SECONDS = 10.0;

    /** what a remark's code must look like - the page shows it as it is */
    public const REMARK_PATTERN = '/^[A-Z][A-Z0-9_]{1,30}$/';

    /** the code of a remark whose own code does not match REMARK_PATTERN - its text tells that code */
    public const BAD_REMARK_CODE = 'REMARK_CODE';

    /** codes of what the run itself reports: a source using one is kept as BAD_REMARK_CODE, so it cannot pass for the run */
    public const RUN_REMARK_CODES = ['SOURCE_CYCLE'];

    /** characters of a bad code kept in the text */
    private const BAD_CODE_CHARS = 40;

    /** characters of a remark's text kept */
    public const REMARK_CHARS = 300;

    /** distinct remarks a step, and a run, tells apart and counts - the rest is dropped */
    public const REMARKS_COUNTED = 1000;

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

    /** @var array<string, array{code: string, text: string}> the first remarks, by code and text */
    private array $remarks = [];

    /** @var ?\WeakMap<self, list<string>> what redact() masks, built once per Context - kept outside
     *  the object, so a dump of it does not show the keys */
    private static ?\WeakMap $masks = null;

    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolver;

    /**
     * @param list<array{vendor: string, context: string}> $credentials from SourceInterface::credentials()
     * @param BatchBudget $budget of the step; each request takes its timeout from it
     * @param ?HttpClientInterface $transport the connection under the guard; another one for tests only
     * @param ?\Closure(string): list<string> $resolver IP addresses of a host name; another one for tests only
     */
    public function __construct(array $credentials, private readonly BatchBudget $budget, int $maxBytes = self::DEFAULT_MAX_BYTES, private readonly ?HttpClientInterface $transport = null, ?\Closure $resolver = null)
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
     * The Context of a source - the one way to build it for one, so the source's own cap on answers applies
     * @param list<array{vendor: string, context: string}> $credentials from SourceInterface::credentials()
     * @param ?HttpClientInterface $transport the connection under the guard; another one for tests only
     * @param ?\Closure(string): list<string> $resolver IP addresses of a host name; another one for tests only
     */
    public static function forSource(SourceInterface $source, array $credentials, BatchBudget $budget, ?HttpClientInterface $transport = null, ?\Closure $resolver = null): self
    {
        return new self($credentials, $budget, $source->maxBytes(), $transport, $resolver);
    }

    /**
     * HTTP(S) only to public addresses, also after a redirect, at most 3 redirects - or none with
     * $follow false: then a 3xx is the answer, so a login never resends its body (a 307/308 would)
     * to another address. The request ends with the step, and once its budget is spent none
     * starts (BatchBudgetSpent - the step's own end, not a failure of the source). The port is not
     * restricted: the source takes its URL from its settings. A non-2xx status is an answer - what it means is up to the caller. DNS is
     * not limited by the budget: neither the guard's lookup nor the second one that sorts a blocked
     * host, so a failing resolver can take up to about twice its timeout.
     */
    public function http(bool $follow = true): ClientInterface
    {
        return new class ($this->send(...), $follow ? self::REDIRECTS : 0) implements ClientInterface {
            /** @param \Closure(string, string, ?string, array<string, string>, int): Response $send */
            public function __construct(private readonly \Closure $send, private readonly int $redirects) {}

            public function request(string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] ?string $body = null, #[SensitiveParameter] array $headers = []): Response
            {
                return ($this->send)($method, $url, $body, $headers, $this->redirects);
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
        self::$masks ??= new \WeakMap();
        self::$masks[$this] ??= $this->masks();
        return str_replace(self::$masks[$this], '***', $text);
    }

    /**
     * A remark for the run's report: something to fix or to know that is no error and does not stop
     * the run - rows left out on purpose, a duplicate id, a definition the source cannot use. The run
     * counts it in its warnings (Run::REMARK, by code) and lists the first ones (Run::REMARKS_KEPT).
     * The same code and text count once in the run, the first REMARKS_COUNTED of them.
     * The text passes redact(), has control characters encoded and is cut to REMARK_CHARS; the page
     * shows it escaped, never expanded. Still, never put a key or personal data in it.
     * @param string $code matching REMARK_PATTERN, e.g. INCOMPLETE_YEAR; another one, or one of
     *                     RUN_REMARK_CODES, is a bug of the source, kept as BAD_REMARK_CODE with that
     *                     code in front of the text - the run goes on
     */
    public function remark(string $code, string $text): void
    {
        if ((preg_match(self::REMARK_PATTERN, $code) !== 1) || in_array($code, self::RUN_REMARK_CODES, true)) {
            $text = 'Bad code ' . mb_substr(self::printable($this->redact($code)), 0, self::BAD_CODE_CHARS) . ': ' . $text;
            $code = self::BAD_REMARK_CODE;
        }
        $text = $this->remarkText($text);
        if (count($this->remarks) < self::REMARKS_COUNTED) {
            $this->remarks[$code . "\0" . $text] = ['code' => $code, 'text' => $text];
        }
    }

    /** $text as a remark keeps it: redacted, control characters encoded, cut to REMARK_CHARS */
    public function remarkText(string $text): string
    {
        // redacted before the cut, which could leave a part of a key unmasked
        return mb_substr(self::printable(trim($this->redact($text))), 0, self::REMARK_CHARS);
    }

    /** for the run: the remarks of the step, each code and text once
     *  @return list<array{code: string, text: string}> */
    public function remarks(): array
    {
        return array_values($this->remarks);
    }

    /** @return list<string> the values redact() masks, the longest first */
    private function masks(): array
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
        return $values;
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

    /**
     * @param array<string, string> $headers
     * @throws BatchBudgetSpent
     */
    private function send(string $method, #[SensitiveParameter] string $url, #[SensitiveParameter] ?string $body, #[SensitiveParameter] array $headers, int $redirects): Response
    {
        // the guard checks this too; kept here so the rule does not depend on the library
        if (preg_match('~^https?://~i', $url) !== 1) {
            throw $this->failure(false, 'Only http(s) addresses are imported', $url, TransportException::ADDRESS);
        }
        $seconds = $this->budget->httpTimeout();
        $this->guarded ??= new NoPrivateNetworkHttpClient($this->transport ?? HttpClient::create());
        $client = new Client(timeout: $seconds, idle: min(self::IDLE_SECONDS, $seconds), agent: Client::agent('import'), redirects: $redirects, http: $this->guarded, maxBytes: $this->maxBytes);

        try {
            $answer = $client->request($method, $url, $body, $headers);
        } catch (ResponseTooLargeException) {
            throw $this->failure(false, "The answer is over {$this->maxBytes} bytes", $url, SourceFailure::TOO_LARGE);
        } catch (TransportException $e) {
            throw $this->transportFailure($e, $url);
        }
        // the guard hands over the last 3xx when redirects ran out; with none allowed it is the answer
        if (($redirects > 0) && ($answer->redirect !== '')) {
            throw $this->failure(false, "More than $redirects redirects", $url, SourceFailure::REDIRECTS);
        }
        return $answer;
    }

    /** Sorts a failed request into "ask again later" and "refused for good" */
    private function transportFailure(#[SensitiveParameter] TransportException $e, #[SensitiveParameter] string $url): SourceFailure
    {
        $kind = $e->kind();
        if ($kind === TransportException::ADDRESS) {
            return $this->failure(false, 'Only well-formed http(s) addresses are imported', $url, $kind);
        }
        $blocked = $e->blockedHost();
        if (!is_null($blocked)) {
            // the guard reports a host it failed to look up as blocked too; ask again
            return $this->resolvesPrivate($blocked)
                ? $this->failure(false, 'Address in a local or private network', $url, TransportException::ADDRESS)
                : $this->failure(true, 'Host name not found', $url, TransportException::DNS);
        }
        return match ($kind) {
            TransportException::TIMEOUT => $this->failure(true, 'No answer in time', $url, $kind),
            TransportException::DNS     => $this->failure(true, 'Host name not found', $url, $kind),
            default                     => $this->failure(true, 'No answer', $url, $kind),
        };
    }

    /** whether the host is, or resolves to, an address the guard blocks; a failed lookup is not */
    private function resolvesPrivate(string $host): bool
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

    /** A failure with AA's own text and the requested URL masked
     *  @param string $detail one of SourceFailure::isDetail() */
    private function failure(bool $retry, string $what, #[SensitiveParameter] string $url, string $detail): SourceFailure
    {
        $message = $what . ': ' . self::printable($this->redact($url));
        return $retry ? new SourceUnavailable('TRANSPORT', $message, $detail) : new SourceRefused('SOURCE', $message, $detail);
    }
}
