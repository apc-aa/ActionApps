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
                $findings[] = $this->ofRun($run, Finding::WARNING, _m('A mailing has not moved on for %1 hours', [intdiv($now - $idle, 3600)]), [], _m('No step of the task queue works on it. Check that cron runs the task queue, then choose Continue by the sending. A reader whose mail may have gone is never sent it again.'), 'stuck', $idle);
            }
        }
        return $findings ?: [new Finding(Finding::OK, _m('%1: nothing found', [static::name()]))];
    }

    private function inError(Run $run): Finding
    {
        $error = $run->lastFailure() ?? ['code' => '', 'where' => '', 'detail' => ''];
        $cause = match ($error['code']) {
            Run::SMTP   => _m('The mail server did not take the mails: check the SMTP settings of this AA or the relay.'),
            Run::LETTER => _m('The mail cannot be made: the Note says why. Fix the item or the list.'),
            default     => _m('Fix what the error code names.'),
        };
        $more = [_m('Error') => safe(trim($error['code'] . ' ' . $error['detail'])), _m('Note') => safe((string) $run->getProperty('note'))];
        // the record keeps no time of the error: it came after the last progress
        return $this->ofRun($run, Finding::ERROR, _m('A mailing stopped by an error'), $more, $cause . ' ' . _m('Nothing more is sent until someone acts: then choose Continue by the sending. A reader whose mail may have gone is never sent it again.'), 'error', $run->idleSince());
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
        return new Finding($severity, $title, since: $since, evidence: [$evidence + $more], advice: $advice, key: "$kind:" . $run->getId());
    }

    // ------------------------------------------------------------ what tests replace

    /** @return class-string<Run> */
    protected function runClass(): string
    {
        return Run::class;
    }
}
