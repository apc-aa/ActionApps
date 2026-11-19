<?php

declare(strict_types=1);

namespace AA\Mailbulk;

/** What became of one mail. */
enum Outcome: string
{
    case Sent      = 'sent';
    /** the server refused this mail for good (a 5xx about the address or the message) */
    case Failed    = 'failed';
    /** nothing to send to this reader */
    case Skipped   = 'skipped';
    /** the message was on its way and no answer came - the server may have it, never sent again */
    case Uncertain = 'uncertain';
    /** nothing sent, the cause is not this reader (connection, a 4xx to the login or the sender, local MTA) - safe to send later */
    case Retry     = 'retry';
    /** nothing sent, the server refused only this mail for now (a 4xx at RCPT or to the message: greylisting, full mailbox) -
     *  safe to send later, but a reader refused again and again should fail rather than hold the sending */
    case Deferred  = 'deferred';
    /** nothing sent, the cause is not this reader and sending on would not help (a 5xx to the login or the sender,
     *  the size limit, the mail settings) - the sending stops until someone fixes it */
    case Halt      = 'halt';
}
