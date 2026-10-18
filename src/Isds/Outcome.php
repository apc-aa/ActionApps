<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * What one attempt to send a message (Client::createMessage()) means for its message and for the run
 * that sends it. Queued: the run tries the same message again; every other state moves the run past
 * it. The state also says where the run's record keeps the attempt: sent - the dmID, uncertain - the
 * time of the attempt, failed - the status of ISDS, queued - nowhere, nothing left.
 * Pure, so that a mass sending and a test message read an answer of ISDS the same way.
 */
final readonly class Outcome
{
    /** go on with the next message */
    public const NEXT = 'next';
    /** wait before the next call to ISDS; a queued message is tried again after the wait, an uncertain one never */
    public const PAUSE = 'pause';
    /** the run fails with $code: no message may go out until a person acts */
    public const FAIL = 'fail';
    /** the run throws the error on: AA does not know what happened, the run fails as INTERNAL */
    public const RETHROW = 'rethrow';

    // the run's codes - BatchFailed::$reason, BatchStepResult::$retry
    public const LOGIN       = 'ISDS_LOGIN';
    /** every login from the server is refused for hours: a new password does not lift it */
    public const BLOCKED     = 'ISDS_BLOCKED';
    public const CONFIG      = 'ISDS_CONFIG';
    public const UNAVAILABLE = 'ISDS_UNAVAILABLE';

    /**
     * @param string $state    Schema::MESSAGE_STATE_*: sent, uncertain, failed, or queued again
     * @param string $event    Schema::EVENT_* of the message's timeline
     * @param string $then     NEXT, PAUSE, FAIL or RETHROW
     * @param string $code     the run's code for PAUSE and FAIL, '' otherwise
     * @param string $dmId     sent: the id ISDS gave the message
     * @param string $isdsCode failed and uncertain: the status or the fault code ISDS answered with, '' = none
     * @param string $text     failed: the words of ISDS, without the login and the password - escape
     *                         it (safe()) when shown
     */
    private function __construct(
        public string $state,
        public string $event,
        public string $then,
        public string $code = '',
        public string $dmId = '',
        public string $isdsCode = '',
        public string $text = '',
    ) {}

    /**
     * Whatever may have been sent is uncertain before anything else: no subclass, of a refused login
     * either, puts such a message back in the queue. Only a refusal keeps a text: ISDS said it and the
     * client took the login and the password out. Every other message may come from curl, PHP or the
     * TLS library and name the key's file. The codes are ISDS's, quoted and redacted by the client too.
     */
    public static function of(SendResult|\Throwable $result): self
    {
        return match (true) {
            $result instanceof SendResult => new self(Schema::MESSAGE_STATE_SENT, Schema::EVENT_SUBMITTED, self::NEXT, dmId: $result->dmId),
            ($result instanceof IsdsException) && $result->mayHaveBeenSent() => (($result instanceof TransportException) && $result->answered())
                // an answer that does not say what ISDS did still shows it is up: the next message may go
                ? new self(Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::NEXT, isdsCode: $result->isdsCode())
                : new self(Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::PAUSE, self::UNAVAILABLE, isdsCode: $result->isdsCode()),
            // before AuthException, which it is
            $result instanceof AddressBlockedException => new self(Schema::MESSAGE_STATE_QUEUED, Schema::EVENT_QUEUED, self::FAIL, self::BLOCKED),
            // nothing was sent; another try only lengthens the block
            $result instanceof AuthException   => new self(Schema::MESSAGE_STATE_QUEUED, Schema::EVENT_QUEUED, self::FAIL, self::LOGIN),
            $result instanceof ConfigException => new self(Schema::MESSAGE_STATE_QUEUED, Schema::EVENT_QUEUED, self::FAIL, self::CONFIG),
            $result instanceof TransportException => new self(Schema::MESSAGE_STATE_QUEUED, Schema::EVENT_QUEUED, self::PAUSE, self::UNAVAILABLE),
            // the class itself only: a subclass AA does not know yet says nothing about the message
            $result::class === IsdsException::class => new self(Schema::MESSAGE_STATE_FAILED, Schema::EVENT_FAILED, self::NEXT, isdsCode: $result->isdsCode(), text: Soap::quote($result->getMessage())),
            // the request may have left before it
            default => new self(Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::RETHROW),
        };
    }
}
