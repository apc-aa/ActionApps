<?php

declare(strict_types=1);

namespace AA\Http;

use RuntimeException;

/** No answer came back at all - a timeout, DNS, a refused connection. Worth trying again later, unless malformedAddress() */
final class TransportException extends RuntimeException
{
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
