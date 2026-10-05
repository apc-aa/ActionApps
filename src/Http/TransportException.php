<?php

declare(strict_types=1);

namespace AA\Http;

use RuntimeException;

/** No answer came back at all - a timeout, DNS, a refused connection. Worth trying again later, unless malformedAddress() */
final class TransportException extends RuntimeException
{
    public const DNS     = 'dns';
    public const TIMEOUT = 'timeout';
    public const TLS     = 'tls';
    public const REFUSED = 'refused';
    public const ADDRESS = 'address';

    /**
     * Why no answer came: ADDRESS (malformedAddress()), DNS, TIMEOUT, TLS, REFUSED, or '' when the
     * library does not tell. Read from the causes only - this exception's own text carries the URL.
     * curl reports the cause as text alone (no error number reaches us), so it is matched against
     * fixed phrases: without the quoted URL, which a redirect lets the source choose, and in an order
     * where a host name (curl names it unquoted) cannot pass for a phrase of another kind.
     */
    public function kind(): string
    {
        if ($this->malformedAddress()) {
            return self::ADDRESS;
        }
        $texts = [];
        for ($cause = $this->getPrevious(); !is_null($cause); $cause = $cause->getPrevious()) {
            if ($cause instanceof \Symfony\Component\HttpClient\Exception\TimeoutException) {
                return self::TIMEOUT;
            }
            // a quote after a letter is an apostrophe (Couldn't), not the start of a quoted name
            $texts[] = preg_replace('/"[^"]*"|(?<![a-z])\'[^\']*\'/i', '', $cause->getMessage());
        }
        $text = implode(' ', $texts);
        return match (true) {
            preg_match('/\btimed out\b|\btimeout (?:was )?reached\b|\bconnection timeout\b|\bmax duration\b/i', $text) === 1          => self::TIMEOUT,
            preg_match('/could not resolve host|getaddrinfo (?:for|failed)|php_network_getaddresses|name or service not known/i', $text) === 1 => self::DNS,
            preg_match('/connection refused|connection reset|failed to connect|could(?:n\'t| not) connect/i', $text) === 1       => self::REFUSED,
            preg_match('/\bSSL\b|\bTLS\b|certificate/i', $text) === 1                                   => self::TLS,
            default                                                                                   => '',
        };
    }

    /**
     * A bad URL or an unsupported scheme, also after a redirect: asking again gets the same. The
     * library reports it as a transport failure; only the cause tells (a redirect to file:// fails
     * in the SSRF guard with a TypeError).
     */
    public function malformedAddress(): bool
    {
        for ($cause = $this->getPrevious(); !is_null($cause); $cause = $cause->getPrevious()) {
            if (($cause instanceof \InvalidArgumentException) || ($cause instanceof \TypeError)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The host the SSRF guard (NoPrivateNetworkHttpClient) refused, also after a redirect; null for
     * any other failure. The guard has no exception class of its own, only this message - if Symfony
     * rewords it, the address stays refused and this returns null. A host name it could not look
     * up is reported the same way, so it may also simply not exist.
     */
    public function blockedHost(): ?string
    {
        for ($cause = $this->getPrevious(); !is_null($cause); $cause = $cause->getPrevious()) {
            if (preg_match('/^(?:Host|IP) "([^"]*)" is blocked for "/', $cause->getMessage(), $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }
}
