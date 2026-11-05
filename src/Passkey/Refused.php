<?php

declare(strict_types=1);

namespace AA\Passkey;

use RuntimeException;

/** A passkey login or registration that does not pass. The reason is a word for the log, never shown to the visitor. */
final class Refused extends RuntimeException
{
    /** the answer is not what a browser sends */
    public const FORMAT = 'format';
    /** no challenge of this session matches - expired, used, or from another session */
    public const CHALLENGE = 'challenge';
    /** made on another page than the one asking - a host behind a proxy AA does not trust shows up here */
    public const ORIGIN = 'origin';
    /** no passkey of this id here - deleted, or made for another AA on the same host */
    public const UNKNOWN = 'unknown';
    /** the authenticator names another account than the passkey belongs to */
    public const USER = 'user';
    /** the signature counter went back - a copy of a security key */
    public const COUNTER = 'counter';
    /** the passkey is already stored */
    public const DUPLICATE = 'duplicate';
    /** the library refused it: signature, user verification, flags ... */
    public const INVALID = 'invalid';

    public function __construct(public readonly string $reason, public readonly string $detail = '')
    {
        parent::__construct($detail === '' ? $reason : "$reason: $detail");
    }
}
