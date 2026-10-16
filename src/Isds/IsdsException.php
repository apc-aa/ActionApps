<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * Whatever the ISDS client throws. Thrown as itself, ISDS refused the request and said so - nothing
 * was created. The subclasses: the login refused (AuthException), come back later
 * (UnavailableException), no answer that says what happened (TransportException). Before sending a
 * message again ask mayHaveBeenSent(), whichever of them it is.
 * The message quotes ISDS: escape it (safe()) when shown.
 */
class IsdsException extends \RuntimeException
{
    /**
     * @param string $isdsCode dmStatusCode or dbStatusCode ('1233'), the local name of a SOAP faultcode
     *                         ('Client'), '' when ISDS gave none - an exception's own code is an int
     */
    public function __construct(string $message, private readonly string $isdsCode = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isdsCode(): string
    {
        return $this->isdsCode;
    }

    /** true: ISDS may have done the work, a message may exist - look it up before sending it again */
    public function mayHaveBeenSent(): bool
    {
        return false;
    }
}
