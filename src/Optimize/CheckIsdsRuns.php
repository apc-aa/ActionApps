<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Isds\Outcome;
use AA\Isds\Schema;
use AA\Isds\SendMaps;
use AA\Isds\SendRun;
use AA\Isds\StatusSync;

/**
 * Mass sendings to data boxes (AA\Isds\SendRun) as their records and campaign items tell: a sending
 * that hangs or stopped by an error, one that left messages of unknown fate or had many fail, following
 * of the delivery that reads nothing or ended with messages not known to be delivered, and a campaign
 * item that does not show how its sending went - or is gone, its run and frozen letter kept as the
 * evidence. Every run with a few small properties and its campaign item by id; a whole record only of
 * a run not finished or finished within RECENT_DAYS.
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

    /** following that read nothing this long has stalled: a round waits up to ROUND + LAG for the next whole
     *  hour, and an outage of ISDS of a few hours is no news */
    private const WATCH_LATE = 3 * 3600 + StatusSync::ROUND + StatusSync::LAG;

    /** what every run is read with */
    protected const PROPS = ['subject', 'state', 'started', 'finished', 'camp_slice', 'msg_slice', 'watch_from', 'watch_last'];

    public static function name(): string
    {
        return _m('Mass sendings to data boxes');
    }

    public static function description(): string
    {
        return _m('Warns about mass sendings to data boxes that hang or stopped by an error, that left messages of unknown fate or had many fail, whose following of the delivery has stalled or left messages not known to be delivered, and about campaigns that do not show how their sending went.');
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
            $state    = $shown[$r['subject'] ?? ''] ?? null;
            $finished = self::isFinished($r['state'] ?? '');
            // following delivery shows the end at its start, the campaign first; one in error may not have
            $from     = self::time($r['watch_from'] ?? '');
            $over     = $finished ? !self::closing($r, $now) : ((($r['state'] ?? '') === SendRun::SENDING) && ($from > 0) && ($from < $now - self::CLOSE_GRACE));
            if (is_null($state)) {
                $gone[$id] = $r;
            } elseif ($over && !in_array($state, [Schema::CAMPAIGN_STATE_DONE, Schema::CAMPAIGN_STATE_STOPPED], true)) {
                $findings[] = $this->ofRun(Finding::WARNING, _m('The campaign of a finished mass sending does not show its end'), (string) $id, $r, $state, [], _m('Nothing writes the campaign item again. Set its state by hand: stopped when the sending was stopped before every message went, otherwise done.'), 'end', $finished ? (int) ($r['finished'] ?? 0) : $from);
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
     * What the record of a run says: stopped by an error, hanging, following delivery that reads
     * nothing, messages of unknown fate once all were tried, too many failed, messages not known to be
     * delivered when following ended
     * @param array<string, string> $r
     * @param ?string               $shown the state of its campaign, null = gone
     * @return list<Finding>
     * @throws \JsonException|\TypeError a map or the position edited by hand
     */
    private function runFindings(SendRun $run, string $id, array $r, ?string $shown, int $now): array
    {
        $maps     = $run->maps();
        $finished = self::isFinished($run->state());
        // every map read, the skips, the delivery and what the items do not show yet too: one edited by
        // hand is a finding before a step trips over it - SendMaps reads such a delivery as none known
        $fates    = $maps->fates();
        SendMaps::entries($maps->dmState);
        if ($run->watching() && !$finished) {
            $run->unshown();
        }
        $failed   = count(array_keys($fates, Schema::MESSAGE_STATE_FAILED, true));
        $open     = $maps->unsettled();
        // following delivery: every message was tried, the sending is over
        $over     = $finished || $run->watching();
        $findings = [];
        $unknown  = [_m('Messages of unknown fate') => (string) count($open)];
        if ($run->state() === SendRun::ERROR) {
            $error = $run->lastFailure() ?? ['code' => '', 'detail' => ''];
            $more  = [_m('Error') => safe(trim($error['code'] . ' ' . $error['detail']))] + $unknown;
            // the record keeps no time of the error: it came after the last progress
            $findings[] = $run->watching()
                ? $this->ofRun(Finding::WARNING, _m('Following the delivery of a mass sending to data boxes stopped by an error'), $id, $r, $shown, $more, _m('All messages went, only finding out whether they were delivered has stopped. Fix what the error code names, then choose Continue on the page of the sending - it reads the states it missed - or Abandon, which ends the following for good.'), 'error', $run->idleSince())
                : $this->ofRun(Finding::ERROR, _m('A mass sending to data boxes stopped by an error'), $id, $r, $shown, $more, ($run->abandonable()
                    // stopped already or every message tried: Stop changes nothing, only Abandon ends it
                    ? _m('Nothing more is sent until someone acts. Fix what the error code names, then choose Continue on the page of the sending - or Abandon, which ends it for good: a message that may have left then stays for a person to verify. The campaign item may still show the sending going on when the error came before the run could write it.')
                    : _m('Nothing more is sent until someone acts. Fix what the error code names, then choose Continue on the page of the sending - or Stop, after which nothing more is sent. A message that may have left is never sent again: the sending looks it up. The campaign item may still show the sending going on when the error came before the run could write it.')), 'error', $run->idleSince());
        } elseif ($run->isStuck($now)) {
            // a pause is no hang: isStuck() counts from the time the next step was due
            $idle       = $run->idleSince();
            $phase      = $run->position()['p'] ?? '';
            $findings[] = $this->ofRun(Finding::WARNING, _m('A mass sending to data boxes has not moved on for %1 hours', [intdiv($now - $idle, 3600)]), $id, $r, $shown, [_m('Phase') => safe(is_string($phase) ? $phase : ''), _m('Idle since') => self::date($idle)] + $unknown, _m('No queue step works on it. Check that cron runs the task queue and that its rounds are long enough for a step (TOEXECUTE_ALLOWED_TIME in config.php), then choose Continue on the page of the sending.'), 'stuck', $idle);
        } else {
            if ($run->watching() && !$finished) {
                array_push($findings, ...$this->watchStands($run, $id, $r, $shown, $now));
            }
            // a running sending looks them up itself, one stopped by an error does on Continue; following
            // delivery looks up nothing, and the office should not learn of them only after weeks
            if ($over && $open) {
                $refs       = array_map($run->senderRef(...), array_slice(array_keys($open), 0, self::EXAMPLES));
                $title      = $finished ? _m('A finished mass sending left messages of unknown fate: %1', [count($open)]) : _m('A mass sending being followed has messages of unknown fate: %1', [count($open)]);
                $findings[] = $this->ofRun(Finding::WARNING, $title, $id, $r, $shown, [_m('Examples') => safe(implode(', ', $refs))], _m('They may have been delivered, so AA never sends them again. Look each up by its sender reference among the sent messages of the data box in the ISDS portal, and send the letter again only to a recipient whose message is not there.'), 'unknown', min($open), count($open));
            }
        }
        $tried = count(array_diff($fates, [Schema::MESSAGE_STATE_SKIPPED]));
        if ($failed && ($over || ($tried >= self::FAILED_SAMPLE)) && ($failed > self::FAILED_SHARE * $tried)) {
            $failures = $maps->failures();
            $codes    = array_count_values($failures);
            arsort($codes);
            $listed     = implode(', ', array_map(static fn(string|int $code, int $n): string => "$code ×$n", array_keys($codes), $codes));
            $advice     = [_m('Those recipients did not get the letter. Their message items show what ISDS said of each: fix the cause and send them the letter again in a new campaign.')];
            if (in_array('3', $failures, true)) {
                $advice[] = _m('Code 3: an attachment did not pass the antivirus check of ISDS (an invalid PDF fails it too). Fix the attachments first: the same attachment fails again.');
            }
            if (in_array('8', $failures, true)) {
                $advice[] = _m('Code 8: the data box of the recipient was made inaccessible. The message is not delivered, though it may show a time of fiction, and no new one to that box arrives: find the new data box of the recipient.');
            }
            $findings[] = $this->ofRun(Finding::WARNING, _m('%1 of %2 messages of a mass sending to data boxes failed', [$failed, $tried]), $id, $r, $shown, [_m('Error') => safe($listed)], implode(' ', $advice), 'failed', 0, $failed);
        }
        // an entry in no shape is no delivery either: the step would have read it again
        $none = ($finished && $run->watching()) ? array_keys(array_filter(self::deliveries($maps), static fn(?int $s): bool => is_null($s) || ($s < 3))) : [];
        if ($none) {
            $refs       = array_map($run->senderRef(...), array_slice($none, 0, self::EXAMPLES));
            $findings[] = $this->ofRun(Finding::WARNING, _m('A mass sending to data boxes ended with messages not known to be delivered: %1', [count($none)]), $id, $r, $shown, [_m('Examples') => safe(implode(', ', $refs))], _m('ISDS told AA neither their delivery nor a refusal while it followed them. Verify each in the ISDS portal by its sender reference among the sent messages of the data box.'), 'undelivered', self::time($r['watch_from'] ?? ''), count($none));
        }
        return $findings;
    }

    /**
     * Following delivery that has read nothing for WATCH_LATE, or says it read up to a time in the future; the
     * last wait tells why, a normal wait for the next round (WATCHING) is none. A reading of the list of sent
     * messages counts from when it started, whatever the windows read meanwhile: past changes ISDS no longer
     * keeps, watch_last moves only once the whole list is read, and a list ISDS keeps breaking off stands.
     * @param array<string, string> $r
     * @return list<Finding>
     */
    private function watchStands(SendRun $run, string $id, array $r, ?string $shown, int $now): array
    {
        $from    = self::time($r['watch_from'] ?? '');
        $last    = self::time($r['watch_last'] ?? '');
        $l       = $run->position()['l'] ?? null;
        $reading = (is_array($l) && is_int($l['h'] ?? null) && ($l['h'] <= $now)) ? $l['h'] : 0;
        $ahead   = $last > $now + StatusSync::ROUND;
        // watch_last starts at the start of the run, long before following
        $since = $ahead ? $from : (($reading > 0) ? max($from, $reading) : max($last, $from));
        if (!$ahead && ($now - $since <= self::WATCH_LATE)) {
            return [];
        }
        $wait    = $run->lastFailure();
        $failing = !is_null($wait) && ($wait['code'] !== SendRun::WATCHING);
        $why     = match (true) {
            !$failing                              => '',
            $wait['detail'] === 'blocked'          => _m('ISDS blocked the address of this server. AA tries again after 24 hours. See the check "%1".', [CheckIsds::name()]),
            $wait['detail'] === 'config'           => _m('PHP cannot read misc/datovka/datovka_conf.php: give it read access to the file again.'),
            $wait['detail'] === 'too_large'        => _m('The data box has so many changes in an hour that AA cannot read them: verify the delivery of the messages in the ISDS portal by their sender references.'),
            $wait['code'] === Outcome::UNAVAILABLE => _m('ISDS did not answer when AA read the delivery of the messages.'),
            default                                => '',
        };
        $advice  = $ahead
            ? _m('The record says the delivery was read up to a time in the future. The next round reads it again from the start of the sending and sends no message again; if this finding persists, check the clocks of the servers that run the task queue.')
            : trim($why . ' ' . _m('AA tries again by itself; once it can read, it reads the states it missed too and sends no message again.'));
        $more = [_m('Read up to') => self::date($last)] + ($failing ? [_m('Last wait') => safe(trim($wait['code'] . ' ' . $wait['detail']))] : []);
        return [$this->ofRun(Finding::WARNING, _m('Following the delivery of a mass sending to data boxes has stalled'), $id, $r, $shown, $more, $advice, 'watch', $since)];
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

    /** a time of the record; one edited out of shape is 0 */
    private static function time(string $value): int
    {
        return (preg_match('/^\d{1,10}\z/', $value) === 1) ? (int) $value : 0;
    }

    /**
     * The dmMessageStatus the record has of each message sent; null = none yet, or an entry edited out
     * of shape
     * @return array<int, ?int>
     * @throws \JsonException dm_ids or dm_state edited into no JSON - SendMaps reads such dm_state as no
     *                        delivery, which would hide it
     */
    protected static function deliveries(SendMaps $maps): array
    {
        $states = SendMaps::entries($maps->dmState);
        $s      = [];
        foreach (array_keys(SendMaps::entries($maps->dmIds)) as $k) {
            $s[$k] = is_string($states[$k] ?? null) ? (StatusSync::entry($states[$k])['s'] ?? null) : null;
        }
        return $s;
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
