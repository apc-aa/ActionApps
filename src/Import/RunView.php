<?php

declare(strict_types=1);

namespace AA\Import;

use AA\Http\TransportException;
use AA\IO\SourceFailure;
use Symfony\Component\HttpFoundation\Response;

/**
 * How the runs of an import and the verdict on it read - the same words and marks in the list of
 * imports and in the detail of one. A *Text method gives plain text, a *Html one HTML with every
 * value escaped.
 */
final class RunView
{
    /** shapes, not font glyphs: the symbols differ from font to font, and the word beside them says it too */
    private const MARKS = [
        'ok'       => '<svg viewBox="0 0 12 12" width="12" height="12"><path d="M1.5 6.5l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
        'warn'     => '<svg viewBox="0 0 12 12" width="12" height="12"><path d="M6 1l5.5 10h-11z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M6 5v3" stroke="currentColor" stroke-width="1.6"/></svg>',
        'error'    => '<svg viewBox="0 0 12 12" width="12" height="12"><path d="M2 2l8 8M10 2l-8 8" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
        'ended'    => '<svg viewBox="0 0 12 12" width="12" height="12"><rect x="2.5" y="2.5" width="7" height="7" fill="currentColor"/></svg>',
        'running'  => '<svg viewBox="0 0 12 12" width="12" height="12"><circle cx="6" cy="6" r="4" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="4 2"/></svg>',
        'baseline' => '<svg viewBox="0 0 12 12" width="12" height="12"><circle cx="6" cy="6" r="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M6 2a4 4 0 0 0 0 8z" fill="currentColor"/></svg>',
        'checked'  => '<svg viewBox="0 0 12 12" width="12" height="12"><circle cx="6" cy="6" r="4" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
        'never'    => '<svg viewBox="0 0 12 12" width="12" height="12"><path d="M2 6h8" stroke="currentColor" stroke-width="2"/></svg>',
    ];

    /** the mark of each kind of health() */
    private const VERDICT_MARKS = ['ok' => 'ok', 'warn' => 'warn', 'bad' => 'error', 'run' => 'running', 'off' => 'checked', 'idle' => 'checked'];

    /** one of MARKS, hidden from screen readers - the word beside it is read instead */
    public static function markHtml(string $mark): string
    {
        return '<span class="aa-import-glyph" aria-hidden="true">' . (self::MARKS[$mark] ?? '') . '</span>';
    }

    /** a verdict of health() - or ok/bad of a check - as its mark and word
     *  @param string $word plain text */
    public static function chipHtml(string $kind, string $word): string
    {
        return '<span class="aa-import-chip" data-kind="' . safe($kind) . '">' . self::markHtml(self::VERDICT_MARKS[$kind] ?? 'checked') . ' ' . safe($word) . '</span>';
    }

    /**
     * How $run went, the one answer for the runs table, the ribbon of the detail and the list: a kind
     * for CSS and its mark (ok, warn, error, ended, running), the word, and what came after. A run
     * ended after an error failed first - the end only closed it - so it reads as a failure.
     * A run that works holds a live lease; one whose next step is due later waits for a retry; one
     * that hangs only the State Check explains.
     * @return array{kind: string, word: string, then: string}
     */
    public static function outcome(Run $run, int $now): array
    {
        $say    = static fn(string $kind, string $word, string $then = ''): array => ['kind' => $kind, 'word' => $word, 'then' => $then];
        $failed = ((string) $run->getProperty('error_code') !== '');
        $note   = json_decode((string) $run->getProperty('progress'), true)['note'] ?? '';
        return match ($run->state()) {
            Run::DONE  => ($run->counts()['errors'] > 0) ? $say('warn', _m('Finished with item errors')) : $say('ok', _m('Finished')),
            Run::ERROR => $say('error', _m('Stopped by an error')),
            Run::ENDED => $failed
                ? $say('error', _m('Stopped by an error'), match ($note) {
                    'SETTINGS_CHANGED' => _m('then ended - the settings changed'),
                    'SCHEDULED_START'  => _m('then ended - a planned run started'),
                    default            => _m('then ended by hand'),
                })
                : $say('ended', match ($note) {
                    'SETTINGS_CHANGED' => _m('Ended - the settings changed'),
                    'SCHEDULED_START'  => _m('Ended - a planned run started'),
                    default            => _m('Ended by hand'),
                }),
            default    => $say('running', match (true) {
                $run->isStuck($now)      => _m('Stuck — see State Check'),
                $run->hasLiveLease($now) => _m('Running'),
                $run->isWaiting($now)    => _m('Waiting to retry'),
                default                  => _m('Queued'),
            }),
        };
    }

    /** outcome() in one line: the word and what came after */
    public static function outcomeText(Run $run, int $now): string
    {
        $outcome = self::outcome($run, $now);
        return ($outcome['then'] === '') ? $outcome['word'] : $outcome['word'] . ', ' . $outcome['then'];
    }

    /** "ended before reading anything" for a run that stopped with all its counts at zero; '' otherwise -
     *  five zeros would look like a clean empty run */
    public static function nothingReadText(Run $run): string
    {
        $counts = $run->counts();
        if (!in_array($run->state(), [Run::ENDED, Run::ERROR], true) || ($counts['read'] + $counts['new'] + $counts['updated'] + $counts['skipped'] + $counts['filtered'] + $counts['errors'] !== 0)) {
            return '';
        }
        return ($run->state() === Run::ENDED) && ((string) $run->getProperty('error_code') === '') ? _m('Ended before reading anything') : _m('Stopped before reading anything');
    }

    /** What a detail of a failure of the source (AA\IO\SourceFailure::$detail) says, plain text; '' for
     *  none, and for a value outside the set - a damaged or forged record says nothing. */
    public static function detailText(string $detail): string
    {
        if (preg_match('/^http:([1-5][0-9]{2})$/D', $detail, $m) === 1) {
            $phrase = Response::$statusTexts[(int) $m[1]] ?? '';
            return _m('The source answered %1.', [trim($m[1] . ' ' . $phrase)]);
        }
        return match ($detail) {
            TransportException::DNS     => _m('The address of the source could not be resolved (DNS).'),
            TransportException::TIMEOUT => _m('The source did not answer in time.'),
            TransportException::TLS     => _m('The secure connection to the source failed (TLS).'),
            TransportException::REFUSED => _m('The source refused the connection.'),
            TransportException::ADDRESS => _m('The address of the source is not allowed or not valid.'),
            SourceFailure::TOO_LARGE    => _m('The answer of the source is too large.'),
            SourceFailure::REDIRECTS    => _m('The source redirected too many times.'),
            default                     => '',
        };
    }

    /** how long ago, roughly; '' when it is too long ago for that to help */
    public static function ageText(int $time, int $now): string
    {
        $seconds = $now - $time;
        return match (true) {
            $time <= 0            => '',
            $seconds < 0          => '',
            $seconds < 60         => _m('just now'),
            $seconds < 3600       => _m('%1 min ago', [intdiv($seconds, 60)]),
            $seconds < 86400      => _m('%1 h ago', [intdiv($seconds, 3600)]),
            $seconds < 86400 * 2  => _m('1 day ago'),
            $seconds < 86400 * 60 => _m('%1 days ago', [intdiv($seconds, 86400)]),
            default               => '',
        };
    }

    /** the time with its age: "2026-10-05 15:00 · 1 h ago" */
    public static function whenText(int $time, int $now): string
    {
        if ($time <= 0) {
            return '--';
        }
        $age = self::ageText($time, $now);
        return date('Y-m-d H:i', $time) . (($age === '') ? '' : ' · ' . $age);
    }

    /**
     * Is the import in order? One verdict for the list and the detail: its kind (ok, warn, bad, run,
     * off, idle - for CSS and the sign), a word and the reason in a sentence. A run in the way or
     * a source that cannot run weighs more than the last import; a disabled import is not failing,
     * it runs only by hand. $mappingOk is true where the caller cannot afford to check it.
     * @param ?Run $newest the newest run - the open one, if any, as only one is ever open
     * @param ?Run $import the newest import (Run::storesItems())
     * @return array{kind: string, word: string, reason: string}
     */
    public static function health(bool $enabled, bool $hasSource, bool $mappingOk, ?Run $newest, ?Run $import, int $now): array
    {
        $say = static fn(string $kind, string $word, string $reason): array => ['kind' => $kind, 'word' => $word, 'reason' => $reason];
        if (!$hasSource) {
            return $say('bad', _m('Failing'), _m('The source of this import is not available, so it cannot run.'));
        }
        if (!$mappingOk) {
            return $say('bad', _m('Failing'), _m('The mapping does not fit the slice, so no run can start until it is fixed.'));
        }
        if ($newest?->state() === Run::ERROR) {
            return $say('bad', _m('Failing'), self::stoppedText($newest));
        }
        if ($newest?->state() === Run::RUNNING) {
            return match (true) {
                $newest->isStuck($now)   => $say('bad', _m('Failing'), _m('A run has made no progress for a long time - cron may not be running its steps.')),
                $newest->isWaiting($now) => $say('warn', _m('Needs attention'), _m('A run failed a step and waits to try again.')),
                default                  => $say('run', _m('Running'), _m('A run is in progress: %1.', [self::outcome($newest, $now)['word']])),
            };
        }
        $errors  = $import?->counts()['errors'] ?? 0;
        $verdict = match (true) {
            $newest === null                => $say('idle', _m('Never ran'), _m('No run yet. Run starts the first import.')),
            $import === null                => $say('idle', _m('No import yet'), _m('Only source checks or dry runs so far - nothing was imported.')),
            $import->state() === Run::ENDED => $say('warn', _m('Needs attention'), (self::outcome($import, $now)['kind'] === 'error')
                ? _m('The last import stopped by an error and was then ended, so the data may be incomplete.')
                : _m('The last import was ended before it finished, so the data may be incomplete.')),
            $errors === 1                   => $say('warn', _m('Needs attention'), _m('The last import finished, but 1 item could not be stored.')),
            $errors > 1                     => $say('warn', _m('Needs attention'), _m('The last import finished, but %1 items could not be stored.', [$errors])),
            ($import->mode() === 'run') && ($import->counts()['read'] === 0) => $say('warn', _m('Needs attention'), _m('The last full import read nothing - the source may be empty.')),
            $import->counts()['read'] === 0 => $say('ok', _m('Healthy'), _m('The last import finished without errors; nothing had changed.')),
            default                         => $say('ok', _m('Healthy'), _m('The last import finished without errors (%1 read).', [$import->counts()['read']])),
        };
        if (!$enabled && in_array($verdict['kind'], ['ok', 'idle'], true)) {
            return $say('off', _m('Disabled'), _m('Runs only by hand.') . ' ' . $verdict['reason']);
        }
        return $verdict;
    }

    /** why a run stopped by an error blocks the import, named by what the run was */
    private static function stoppedText(Run $run): string
    {
        return match (true) {
            $run->isDry()               => _m('The last dry run stopped by an error. Nothing more is imported until you act.'),
            $run->mode() === 'check'    => _m('The last source check stopped by an error. Nothing more is imported until you act.'),
            $run->mode() === 'baseline' => _m('Recording the state of the source stopped by an error. Nothing more is imported until you act.'),
            default                     => _m('The last import stopped by an error. Nothing more is imported until you act.'),
        };
    }

    /** what a run did, in a few words, plain text - the one wording of a run that imported nothing,
     *  wherever it is shown */
    public static function resultText(Run $run, int $now): string
    {
        $kind   = self::outcome($run, $now)['kind'];
        $counts = $run->counts();
        $check  = ($run->mode() === 'check');
        $text   = match (true) {
            $check && ($kind === 'error')                        => _m('Source check failed - nothing imported'),
            $check && ($kind === 'running')                      => _m('Source check under way'),
            in_array($kind, ['error', 'ended', 'running'], true) => self::outcomeText($run, $now),
            $check                                               => _m('Source reachable - nothing imported'),
            $run->mode() === 'baseline'                          => self::baselineText($counts),
            ($counts['read'] === 0) && ($run->mode() === 'changed') => _m('Nothing had changed'),
            $counts['read'] === 0                                => _m('Nothing read'),
            default                                              => _m('%1 read, %2 new, %3 changed', [$counts['read'], $counts['new'], $counts['updated']]),
        };
        return $run->isDry() ? _m('%1 (nothing stored)', [$text]) : $text;
    }

    /** @param array{streams: int, unversioned: int} $counts */
    private static function baselineText(array $counts): string
    {
        $text = ($counts['streams'] === 1) ? _m('Recorded the state of 1 stream - nothing imported') : _m('Recorded the state of %1 streams - nothing imported', [$counts['streams']]);
        return ($counts['unversioned'] > 0) ? $text . '. ' . _m('%1 without a version', [$counts['unversioned']]) : $text;
    }

    /** how $run ran - its mode, dry, its trigger - in words, plain text */
    public static function howItRan(Run $run): string
    {
        $trigger = (string) $run->getProperty('trigger');
        $about   = [
            match ($run->mode()) {
                'changed'  => _m('changed only'),
                'check'    => _m('check'),
                'baseline' => _m('recording the state of the source'),
                default    => '',
            },
            $run->isDry() ? _m('dry run') : '',
            match (true) {
                $trigger === 'manual'               => _m('by hand'),
                str_starts_with($trigger, 'plan:')  => _m('planned'),
                str_starts_with($trigger, 'event:') => _m('on an event'),
                default                             => '',
            },
        ];
        return implode(', ', array_filter($about));
    }

    /** the verdict of health() for a row of the list, and under it why
     *  @param array{kind: string, word: string, reason: string} $verdict */
    public static function statusHtml(array $verdict, bool $enabled): string
    {
        return self::chipHtml($verdict['kind'], $verdict['word'])
            . '<small class="aa-import-sub">' . safe($verdict['reason']) . '</small>'
            . ((!$enabled && ($verdict['kind'] !== 'off')) ? '<small class="aa-import-sub aa-import-off">' . safe(_m('Disabled - it does not start')) . '</small>' : '');
    }

    /** The newest run for a row of the list: when, how, what it did; when it stored nothing, also
     *  when the last import was, as that is when the data last changed.
     *  @param ?Run $import the newest import (Run::storesItems()) */
    public static function lastRunHtml(int $now, ?Run $run, ?Run $import): string
    {
        if ($run === null) {
            return '<span class="aa-import-none">' . safe(_m('No run yet')) . '</span>';
        }
        $started = (int) $run->getProperty('started');
        $age     = self::ageText($started, $now);
        $how     = self::howItRan($run);
        $errors  = $run->counts()['errors'];
        $sub     = static fn(string $text, string $class = ''): string => '<small class="aa-import-sub' . $class . '">' . safe($text) . '</small>';
        return ($started ? date('Y-m-d H:i', $started) : '--')
            . (($age === '') ? '' : ' ' . $sub($age))
            . (($how === '') ? '' : $sub($how))
            . $sub(self::resultText($run, $now))
            . match (true) {
                $errors === 1 => $sub(_m('1 item error'), ' aa-import-errors'),
                $errors > 1   => $sub(_m('%1 item errors', [$errors]), ' aa-import-errors'),
                default       => '',
            }
        . ((($import !== null) && ((string) $import->getId() !== (string) $run->getId())) ? $sub(_m('Last import: %1', [self::whenText((int) $import->getProperty('started'), $now)])) : '');
    }
}
