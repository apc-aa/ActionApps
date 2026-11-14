<?php

declare(strict_types=1);

namespace AA\Mailbulk;

/**
 * Sends one message at a time. prepare() does all that may be slow (images included) and sends nothing,
 * so the moment in which a crash leaves the outcome unknown is the send() alone.
 */
interface Mailer
{
    public function prepare(Message $message): void;

    /** sends the prepared message - once */
    public function send(): Outcome;

    /** what the transport said about the last send() */
    public function detail(): string;
}
