<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\SourceFailure;

/**
 * What Service::start() and resume() did:
 * - queued: runId = the run;
 * - busy: runId = the run in the way, reason = its state (running, error), LEASE (a finished run -
 *   ended or done - whose last step may still store items; for Continue, the run's own last step) or
 *   WRONG_STATE (Continue while its step is on its way);
 * - off: a planned start of a disabled import;
 * - unknown: no such import or run here;
 * - invalid: reason = MODE, OPTIONS, TRIGGER (no task of the slice, or a task run on an event),
 *   FORBIDDEN, NO_TRANSACTION, NO_SOURCE, DEFINITION, SETTINGS (a setting its property refuses),
 *   SLICE (a slice of the settings the user does not import into), STREAMS, SOURCE (the source's
 *   code failed), KEYS (a baseline, or its Continue, of a source with keys by a user who is no
 *   superadmin), NO_VERSIONS (a baseline of a source that is not Probeable), a code the source
 *   refused with (AUTH, NO_CREDENTIAL, TRANSPORT, ...), WRONG_STATE (Continue of a finished run).
 */
final class StartResult
{
    public const CODES = ['queued', 'busy', 'off', 'unknown', 'invalid'];

    /** @param string $detail of a refusal of the source, as AA\IO\SourceFailure::$detail */
    public function __construct(
        public readonly string $code,
        public readonly string $runId = '',
        public readonly string $reason = '',
        public readonly string $detail = '',
    ) {
        if (!in_array($code, self::CODES, true)) {
            throw new \InvalidArgumentException("No start result $code");
        }
        if (!SourceFailure::isDetail($detail)) {
            throw new \InvalidArgumentException('A start result detail must be one of SourceFailure::isDetail()');
        }
    }
}
