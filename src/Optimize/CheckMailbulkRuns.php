<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Mailbulk\Run;

/**
 * Mailings of an item to the readers of a list (AA\Mailbulk\Run) go on in the task queue, long after
 * the page that started them was closed: a queue that does not run leaves one hanging, an error stops
 * one till someone acts. Only unfinished runs are read, found through the index of their state.
 */
class CheckMailbulkRuns extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Mailings to readers');
    }

    public static function description(): string
    {
        return _m('Warns about mailings of an item to the readers of a list that hang or stopped by an error.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'mail';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    /** @return Finding[] */
    public function findings(): array
    {
        $now      = $this->runClass()::clock();
        $findings = [];
        foreach ($this->runClass()::unfinished() as $run) {
            if ($run->state() === Run::ERROR) {
                $findings[] = $this->inError($run);
            } elseif ($run->isStuck($now)) {
                // a retry waiting is no hang: isStuck() counts from the time the next step was due
                $idle       = $run->idleSince();
                $findings[] = $this->ofRun($run, Finding::WARNING, _m('Mailing "%1" has not moved on for %2 hours', [self::title($run), intdiv($now - $idle, 3600)]), self::failure($run), _m('No step of the task queue works on it. Check that cron runs the task queue, then choose Continue by the sending. A reader whose mail may have gone is never sent it again.'), 'stuck', $idle);
            }
        }
        return $findings ?: [new Finding(Finding::OK, _m('%1: nothing found', [static::name()]))];
    }

    private function inError(Run $run): Finding
    {
        $cause = match ($run->lastFailure()['code'] ?? '') {
            Run::SMTP       => _m('The mail server did not take the mails: check the SMTP settings of this AA or the relay.'),
            Run::LETTER     => _m('The mail cannot be made: the Note says why. Fix the item or the list.'),
            Run::RECIPIENTS => _m('The readers frozen for this mailing are missing: ask the administrator.'),
            default         => _m('Fix what the error code names.'),
        };
        // the record keeps no time of the error: it came after the last progress
        return $this->ofRun($run, Finding::ERROR, _m('Mailing "%1" stopped by an error', [self::title($run)]), self::failure($run), $cause . ' ' . _m('Nothing more is sent until someone acts: then choose Continue by the sending. A reader whose mail may have gone is never sent it again.'), 'error', $run->idleSince());
    }

    /** the error of the run, or the failure its retry waits for, and its note - HTML-escaped; none when there is neither
     *  @return array<string, string> */
    private static function failure(Run $run): array
    {
        $error = $run->lastFailure();
        $note  = (string) $run->getProperty('note');
        $more  = ($error === null) ? [] : [_m('Error') => safe(trim($error['code'] . ' ' . $error['detail']))];
        return $more + (($note === '') ? [] : [_m('Note') => safe($note)]);
    }

    /** the name of the mailing for people - its ids are in the evidence */
    private static function title(Run $run): string
    {
        return ((string) $run->getProperty('title')) ?: _m('without a title');
    }

    /** @param array<string, string> $more further evidence, HTML-escaped */
    private function ofRun(Run $run, string $severity, string $title, array $more, string $advice, string $kind, int $since): Finding
    {
        $evidence = [
            _m('Run')     => safe((string) $run->getId()),
            _m('Item')    => safe((string) $run->getProperty('subject')),
            _m('List')    => safe((string) $run->getProperty('list')),
            _m('Mailing') => safe((string) $run->getProperty('title')),
            _m('Started') => safe(date('Y-m-d H:i', (int) $run->getProperty('started'))),
            _m('Tried')   => (int) $run->getProperty('cursor') . ' / ' . (int) $run->getProperty('total'),
        ];
        return new Finding($severity, $title, since: $since, evidence: [$evidence + $more], advice: $advice, key: "$kind:" . $run->getId(), place: self::title($run));
    }

    // ------------------------------------------------------------ what tests replace

    /** @return class-string<Run> */
    protected function runClass(): string
    {
        return Run::class;
    }
}
