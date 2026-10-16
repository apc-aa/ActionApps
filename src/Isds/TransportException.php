<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Http\TransportException as HttpTransportException;

/**
 * No answer that says whether ISDS did the work: no answer at all, HTTP 5xx, a fault of the server,
 * an internal error of ISDS, or something else than the SOAP answer of the operation. For a sent
 * message that means "maybe delivered" - unless mayHaveBeenSent() is false, never send it again
 * because of this exception, look it up in the list of sent messages first.
 */
final class TransportException extends IsdsException
{
    public function __construct(string $message, private readonly bool $mayHaveBeenSent, string $isdsCode = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $isdsCode, $previous);
    }

    public function mayHaveBeenSent(): bool
    {
        return $this->mayHaveBeenSent;
    }

    /**
     * Not sent only when the request cannot have left: a malformed address, a name that did not
     * resolve. A timeout, a reset or a TLS error may come after ISDS got the request, and a needless
     * lookup costs less than a letter delivered twice. This holds only because the client never
     * reuses a connection (Client::http()): libcurl sends a request again by itself on a kept-alive
     * connection the server closed, and then reports just the second attempt.
     */
    public static function fromHttp(HttpTransportException $e): self
    {
        $sent = !in_array($e->kind(), [HttpTransportException::ADDRESS, HttpTransportException::DNS], true);
        return new self('ISDS gave no answer: ' . $e->getMessage(), $sent, '', $e);
    }

    /** an answer came, but it does not say whether the work was done */
    public static function uncertain(string $message, string $isdsCode = '', ?\Throwable $previous = null): self
    {
        return new self($message, true, $isdsCode, $previous);
    }
}
