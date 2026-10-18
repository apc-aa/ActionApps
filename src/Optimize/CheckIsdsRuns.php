<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Isds\Schema;
use AA\Isds\SendMaps;
use AA\Isds\SendRun;

/**
 * Mass sendings to data boxes (AA\Isds\SendRun) as their records and campaign items tell: a sending
 * that hangs or stopped by an error, one that left messages of unknown fate or had many fail, and a
 * campaign item that does not show how its sending went - or is gone, its run and frozen letter kept as
 * the evidence. Every run with a few small properties and its campaign item by id; a whole record only
 * of a run not finished or finished within RECENT_DAYS.
 */
class CheckIsdsRuns extends AbstractOptimize
{
    /** a finished sending is read whole this long: by then the office has seen how it went, and the
     *  records of years of sendings, a dmID of each message, would not fit a basic check */
    public const RECENT_DAYS = 30;

    /** ids a finding lists at most */
    protected const EXAMPLES = 5;

    /** a larger share of the messages tried that failed warns - in a finished sending, or once this
     *  many were tried: one refusal among the first three is no trend */
    private const FAILED_SHARE  = 0.05;
    private const FAILED_SAMPLE = 20;

    /** a sending given up writes its campaign and items from the queue after its end (afterClose()),
     *  once the lease of its last step ran out - till then they are not wrong yet */
    private const CLOSE_GRACE = 3600;

    /** items one GetItemContent() reads: a campaign has thousands of messages */
    private const READ_CHUNK = 500;

    /** what every run is read with */
    protected const PROPS = ['subject', 'state', 'started', 'finished', 'camp_slice', 'msg_slice'];

    public static function name(): string
    {
        return _m('Mass sendings to data boxes');
    }

    public static function description(): string
    {
        return _m('Warns about mass sendings to data boxes that hang or stopped by an error, that left messages of unknown fate or had many fail, and about campaigns that do not show how their sending went.');
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
        $records  = $this->runClass()::recordsOfAll(self::PROPS);
        $shown    = $this->campaignStates($records);
        $findings = $this->campaignFindings($records, $shown, $now);
        foreach ($this->recentRuns($records, $now) as $id => $run) {
            $r = $records[$id];
            try {
                array_push($findings, ...$this->runFindings($run, $id, $r, $shown[$r['subject'] ?? ''] ?? null, $now));
            } catch (\JsonException|\TypeError) {
                // a map or the position edited by hand: the other runs are still checked
                $findings[] = $this->unreadable($id, $r, $shown[$r['subject'] ?? ''] ?? null);
            }
        }
        return $findings ?: [new Finding(Finding::OK, _m('%1: nothing found', [static::name()]))];
    }

    /**
     * A campaign item gone, or one that does not show the end of its sending
     * @param array<string, array<string, string>> $records
     * @param array<string, string>                $shown campaign => its state
     * @return list<Finding>
     */
    private function campaignFindings(array $records, array $shown, int $now): array
    {
        $findings = [];
        $gone     = [];
        foreach ($records as $id => $r) {
            $state = $shown[$r['subject'] ?? ''] ?? null;
            if (is_null($state)) {
                $gone[$id] = $r;
            } elseif (self::isFinished($r['state'] ?? '') && !self::closing($r, $now) && !in_array($state, [Schema::CAMPAIGN_STATE_DONE, Schema::CAMPAIGN_STATE_STOPPED], true)) {
                $findings[] = $this->ofRun(Finding::WARNING, _m('The campaign of a finished mass sending does not show its end'), (string) $id, $r, $state, [], _m('Nothing writes the campaign item again. Set its state by hand: stopped when the sending was stopped before every message went, otherwise done.'), 'end', (int) ($r['finished'] ?? 0));
            }
        }
        if ($gone) {
            // a sending going on first
            $going      = array_filter($gone, static fn(array $r): bool => !self::isFinished($r['state'] ?? ''));
            $listed     = array_slice($going + $gone, 0, self::EXAMPLES, true);
            $findings[] = new Finding(
                $going ? Finding::WARNING : Finding::INFO,
                _m('Mass sendings to data boxes whose campaign item is gone: %1', [count($gone)]),
                count: count($gone),
                since: min(array_map(static fn(array $r): int => (int) ($r['started'] ?? 0), $gone)),
                evidence: array_map(fn($id, array $r): array => $this->about((string) $id, $r, null), array_keys($listed), $listed),
                advice: _m('Their records and frozen letters stay as the evidence of what was sent - AA does not delete them with the campaign. A sending not finished goes on to its end: its record holds whom and what it sends.'),
                key: 'gone',
            );
        }
        return $findings;
    }

    /**
     * What the record of a run says: stopped by an error, hanging, finished with messages of unknown
     * fate, too many failed
     * @param array<string, string> $r
     * @param ?string               $shown the state of its campaign, null = gone
     * @return list<Finding>
     * @throws \JsonException|\TypeError a map or the position edited by hand
     */
    private function runFindings(SendRun $run, string $id, array $r, ?string $shown, int $now): array
    {
        $maps     = $run->maps();
        // every map read, the skips too: one edited by hand is a finding before a step trips over it
        $fates    = $maps->fates();
        $sent     = SendMaps::entries($maps->dmIds);
        $failed   = array_diff_key(SendMaps::entries($maps->failed), $sent);
        $open     = $maps->unsettled();
        $finished = self::isFinished($run->state());
        $findings = [];
        $unknown  = [_m('Messages of unknown fate') => (string) count($open)];
        if ($run->state() === SendRun::ERROR) {
            $error = $run->lastFailure() ?? ['code' => '', 'detail' => ''];
            // the record keeps no time of the error: it came after the last progress
            $findings[] = $this->ofRun(Finding::ERROR, _m('A mass sending to data boxes stopped by an error'), $id, $r, $shown, [_m('Error') => safe(trim($error['code'] . ' ' . $error['detail']))] + $unknown, _m('Nothing more is sent until someone acts. Fix what the error code names, then choose Continue on the page of the sending - or Stop, after which nothing more is sent. A message that may have left is never sent again: the sending looks it up. The campaign item may still show the sending going on when the error came before the run could write it.'), 'error', $run->idleSince());
        } elseif ($run->isStuck($now)) {
            // a pause is no hang: isStuck() counts from the time the next step was due
            $idle       = $run->idleSince();
            $phase      = $run->position()['p'] ?? '';
            $findings[] = $this->ofRun(Finding::WARNING, _m('A mass sending to data boxes has not moved on for %1 hours', [intdiv($now - $idle, 3600)]), $id, $r, $shown, [_m('Phase') => safe(is_string($phase) ? $phase : ''), _m('Idle since') => self::date($idle)] + $unknown, _m('No queue step works on it. Check that cron runs the task queue and that its rounds are long enough for a step (TOEXECUTE_ALLOWED_TIME in config.php), then choose Continue on the page of the sending.'), 'stuck', $idle);
        } elseif ($finished && $open) {
            // a running sending looks them up itself, one stopped by an error does on Continue
            $refs       = array_map($run->senderRef(...), array_slice(array_keys($open), 0, self::EXAMPLES));
            $findings[] = $this->ofRun(Finding::WARNING, _m('A finished mass sending left messages of unknown fate: %1', [count($open)]), $id, $r, $shown, [_m('Examples') => safe(implode(', ', $refs))], _m('They may have been delivered, so AA never sends them again. Look each up by its sender reference among the sent messages of the data box in the ISDS portal, and send the letter again only to a recipient whose message is not there.'), 'unknown', min($open), count($open));
        }
        $tried = count(array_diff($fates, [Schema::MESSAGE_STATE_SKIPPED]));
        if ($failed && ($finished || ($tried >= self::FAILED_SAMPLE)) && (count($failed) > self::FAILED_SHARE * $tried)) {
            $codes = array_count_values(array_map('strval', $failed));
            arsort($codes);
            $listed     = implode(', ', array_map(static fn(string $code, int $n): string => "$code ×$n", array_keys($codes), $codes));
            $findings[] = $this->ofRun(Finding::WARNING, _m('%1 of %2 messages of a mass sending to data boxes failed', [count($failed), $tried]), $id, $r, $shown, [_m('Error') => safe($listed)], _m('Those recipients did not get the letter. Their message items show what ISDS said of each: fix the cause and send them the letter again in a new campaign.'), 'failed', 0, count($failed));
        }
        return $findings;
    }

    /** @param array<string, string> $r */
    protected function unreadable(string $id, array $r, ?string $shown): Finding
    {
        return $this->ofRun(Finding::ERROR, _m('The record of a mass sending to data boxes cannot be read'), $id, $r, $shown, [], _m('The record or the frozen recipients of the sending were changed outside AA. Restore them from a backup of the database before anyone chooses Continue: the record is what keeps a message from going twice.'), 'record');
    }

    /**
     * A finding of the run $id; it links the campaign item when there is one
     * @param array<string, string> $r
     * @param ?string               $shown the state of its campaign, null = gone
     * @param array<string, string> $more  further evidence, HTML-escaped
     * @param string                $kind  the key, with the run id
     */
    protected function ofRun(string $severity, string $title, string $id, array $r, ?string $shown, array $more, string $advice, string $kind, int $since = 0, int $count = 0): Finding
    {
        // an item just read: its id and its slice's are valid
        $links = is_null($shown) ? [] : [_m('Campaign item') => AA_INSTAL_PATH . 'admin/itemedit.php?' . http_build_query(['id' => $r['subject'] ?? '', 'change_id' => $r['camp_slice'] ?? '', 'edit' => 1])];
        return new Finding($severity, $title, $count, $since, [$this->about($id, $r, $shown) + $more], $advice, $links, key: "$kind:$id", place: $this->sliceName($r['camp_slice'] ?? ''));
    }

    /**
     * @param array<string, array<string, string>> $records
     * @return array<string, SendRun> run id => the run, of those not finished or finished within RECENT_DAYS
     */
    protected function recentRuns(array $records, int $now): array
    {
        $runs = [];
        foreach ($records as $id => $r) {
            if (!self::isFinished($r['state'] ?? '') || ((int) ($r['finished'] ?? 0) > $now - self::RECENT_DAYS * 86400)) {
                $run = $this->runClass()::get((string) $id);
                if ($run) {
                    $runs[(string) $id] = $run;
                }
            }
        }
        return $runs;
    }

    /**
     * @param array<string, array<string, string>> $records
     * @return array<string, string> campaign => the state its item shows; a campaign gone, or no longer in its slice, is missing
     */
    protected function campaignStates(array $records): array
    {
        $bySlice = [];
        foreach ($records as $r) {
            $bySlice[strtolower($r['camp_slice'] ?? '')][$r['subject'] ?? ''] = true;
        }
        $shown = [];
        foreach ($bySlice as $slice => $campaigns) {
            $shown += $this->fieldOf((string) $slice, array_map('strval', array_keys($campaigns)), Schema::CAMPAIGN_STATE);
        }
        return $shown;
    }

    /**
     * @param array<string, string> $r
     * @return array<string, string> the evidence every finding of a run starts with
     */
    protected function about(string $id, array $r, ?string $shown): array
    {
        return [_m('Run') => safe($id), _m('Campaign item') => safe($r['subject'] ?? '')] + (is_null($shown) ? [] : [_m('Campaign state') => safe($shown)])
            + [_m('Started') => self::date((int) ($r['started'] ?? 0)), _m('State') => safe($r['state'] ?? '')];
    }

    protected static function isFinished(string $state): bool
    {
        return in_array($state, [SendRun::DONE, SendRun::ENDED], true);
    }

    /** @param array<string, string> $r a run that finished less than CLOSE_GRACE ago */
    protected static function closing(array $r, int $now): bool
    {
        return self::isFinished($r['state'] ?? '') && ((int) ($r['finished'] ?? 0) >= $now - self::CLOSE_GRACE);
    }

    private static function date(int $time): string
    {
        return ($time > 0) ? safe(date('Y-m-d H:i', $time)) : '';
    }

    /**
     * $field of the items $ids of $slice, by id with the slice open - its reading password would mask
     * the value - READ_CHUNK at a time. withSlicesOpen() wants no item content out of it: these are
     * state words of the sending, for State Check only.
     * @param list<string> $ids
     * @return array<string, string> id in each spelling given => value; an item gone, of another slice or not an id is left out
     */
    protected function fieldOf(string $slice, array $ids, string $field): array
    {
        // GetItemContent() answers in lowercase, whatever case a record was edited to
        $slice = strtolower($slice);
        $asked = [];
        foreach (array_filter($ids, 'is_long_id') as $id) {
            $asked[strtolower($id)][] = $id;
        }
        if (!is_long_id($slice) || !$asked) {
            return [];
        }
        return \AA_Credentials::singleton()->withSlicesOpen([$slice], function () use ($slice, $asked, $field): array {
            $values = [];
            foreach (array_chunk(array_map('strval', array_keys($asked)), self::READ_CHUNK) as $chunk) {
                foreach ($this->content($chunk, $field) as $id => $item) {
                    if (isset($asked[$id]) && (($item['u_slice_id......'][0]['value'] ?? '') === $slice)) {
                        $values += array_fill_keys($asked[$id], (string) ($item[$field][0]['value'] ?? ''));
                    }
                }
            }
            return $values;
        });
    }

    // ------------------------------------------------------------ what tests replace

    /** @return class-string<SendRun> */
    protected function runClass(): string
    {
        return SendRun::class;
    }

    /**
     * @param list<string> $ids
     * @return array<array-key, mixed> GetItemContent() of $ids, $field only
     */
    protected function content(array $ids, string $field): array
    {
        return GetItemContent(new \zids($ids, 'l'), false, false, [$field]);
    }

    protected function sliceName(string $slice): string
    {
        return \AA_Slice::getModuleName($slice);
    }
}
