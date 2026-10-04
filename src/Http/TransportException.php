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
}
