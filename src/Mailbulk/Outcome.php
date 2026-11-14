<?php

declare(strict_types=1);

namespace AA\Mailbulk;

/** What became of one mail. */
enum Outcome: string
{
    case Sent      = 'sent';
    /** the address or the server refused it for good */
    case Failed    = 'failed';
    /** nothing to send to this reader */
    case Skipped   = 'skipped';
    /** the server may have taken it - never sent again */
    case Uncertain = 'uncertain';
    /** a transient failure before the message went out - safe to send later */
    case Retry     = 'retry';
}
