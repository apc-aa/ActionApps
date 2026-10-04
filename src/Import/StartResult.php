<?php

declare(strict_types=1);

namespace AA\Import;

/**
 * What Service::start() and resume() did:
 * - queued: runId = the run;
 * - busy: runId = the run in the way, reason = its state (running, error), LEASE (a finished run -
 *   ended or done - whose last step may still store items) or WRONG_STATE (Continue while its step is
 *   on its way);
 * - off: a planned start of a disabled import;
 * - unknown: no such import or run here;
 * - invalid: reason = MODE, OPTIONS, TRIGGER (no task of the slice, or a task run on an event),
 *   FORBIDDEN, NO_TRANSACTION, NO_SOURCE, DEFINITION, SETTINGS (a setting its property refuses),
 *   SLICE (a slice of the settings the user does not import into), STREAMS, SOURCE (the source's
 *   code failed), a code the source refused with (AUTH, NO_CREDENTIAL, TRANSPORT, ...), WRONG_STATE
 *   (Continue of a finished run).
 */
final class StartResult
{
    public const CODES = ['queued', 'busy', 'off', 'unknown', 'invalid'];

    public function __construct(
        public readonly string $code,
        public readonly string $runId = '',
        public readonly string $reason = '',
    ) {
        if (!in_array($code, self::CODES, true)) {
            throw new \InvalidArgumentException("No start result $code");
        }
    }
}
