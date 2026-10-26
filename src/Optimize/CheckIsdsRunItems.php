<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Isds\Schema;
use AA\Later\BatchFailed;

/**
 * The message items of the sendings CheckIsdsRuns reads whole, against the records of their runs: an
 * item that does not show the fate the record has for its message (SendMaps::fates()) or, of a sending
 * that followed delivery, its dmMessageStatus (dm_state) - a write of the item failed after the
 * record's, and nothing writes it again - or shows sending with no message on its way. The frozen
 * recipients name the items, which are read by id, never searched for by value.
 */
class CheckIsdsRunItems extends CheckIsdsRuns
{
    public static function name(): string
    {
        return _m('Message items of mass sendings');
    }

    public static function description(): string
    {
        return _m('Compares the message items of recent mass sendings to data boxes with the records of their runs, which the sending goes by.');
    }

    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    /** @return Finding[] */
    public function findings(): array
    {
        $class    = $this->runClass();
        $records  = $class::recordsOfAll(self::PROPS);
        $shown    = $this->campaignStates($records);
        $findings = [];
        foreach ($this->recentRuns($records, $class::clock()) as $id => $run) {
            $r = $records[$id];
            // a step writes the record, then the items: they differ while one may still work
            if ($run->stepMayStillWork($class::clock()) || self::closing($r, $class::clock())) {
                continue;
            }
            $finished = self::isFinished($run->state());
            try {
                $rows     = $run->recipients();
                $fates    = $run->maps()->fates();
                $delivery = $run->watching() ? array_filter(self::deliveries($run->maps()), is_int(...)) : [];
                // a step of following still writes these; once it ended, an item that does not show the record is wrong
                $unshown  = ($run->watching() && !$finished) ? $run->unshown() : [];
            } catch (BatchFailed) {
                $findings[] = $this->unreadable($id, $r, $shown[$r['subject'] ?? ''] ?? null);
                continue;
            } catch (\JsonException) {
                // CheckIsdsRuns reports a map edited by hand
                continue;
            }
            $states = $this->fieldOf($r['msg_slice'] ?? '', array_column($rows, 'msg'), Schema::MESSAGE_STATE);
            $dm     = $delivery ? $this->fieldOf($r['msg_slice'] ?? '', array_column(array_diff_key(array_intersect_key($rows, $delivery), $unshown), 'msg'), Schema::MESSAGE_DM_STATUS) : [];
            // a step that claimed the run meanwhile may have written the items after the record was read
            $again = $class::get($id);
            if (is_null($again) || $again->stepMayStillWork($class::clock()) || ($again->step() !== $run->step())) {
                continue;
            }
            // following delivery looks up nothing any more
            $over = $finished || $run->watching();
            [$n, $examples] = [0, []];
            foreach (array_diff_key($rows, $unshown) as $k => $row) {
                $shows  = $states[$row['msg']] ?? null;
                $should = $fates[$k] ?? Schema::MESSAGE_STATE_QUEUED;
                $wrong  = match (true) {
                    is_null($shows) => false,
                    // never tried: prep, a stop or a frozen reason gave it its state - only sending is wrong
                    !isset($fates[$k]) => $shows === Schema::MESSAGE_STATE_SENDING,
                    // of unknown fate: a running sending looks it up and writes the item itself, and an item
                    // set sent or failed is a person's answer from the ISDS portal
                    $should === Schema::MESSAGE_STATE_UNCERTAIN => $over && in_array($shows, [Schema::MESSAGE_STATE_QUEUED, Schema::MESSAGE_STATE_SENDING], true),
                    default => $shows !== $should,
                };
                $status = $dm[$row['msg']] ?? null;
                // named: the item shows a word of MESSAGE_STATE too
                if (!$wrong && !is_null($status) && ($status !== (string) $delivery[$k])) {
                    [$wrong, $shows, $should] = [true, 'dmMessageStatus ' . (($status === '') ? '–' : $status), (string) $delivery[$k]];
                }
                if ($wrong && (++$n <= self::EXAMPLES)) {
                    $examples[] = $run->senderRef($k) . " ({$row['msg']}): $shows → $should";
                }
            }
            if ($n) {
                $findings[] = $this->ofRun(Finding::WARNING, _m('Message items of a mass sending do not show what its record says: %1', [$n]), $id, $r, $shown[$r['subject'] ?? ''] ?? null, [_m('Examples') => safe(implode(', ', $examples))], _m('The sending goes by its record; the items only show it to people, and an item that shows a message as not sent though it went makes someone send it again by hand. Each example is the sender reference, the item, what it shows and what the record says: set the state of the item to that, or verify the message in the ISDS portal by its sender reference.'), 'items', 0, $n);
            }
        }
        return $findings ?: [new Finding(Finding::OK, _m('%1: nothing found', [static::name()]))];
    }
}
