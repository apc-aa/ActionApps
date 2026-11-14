<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA;
use AA\IO\DB\DB_AA;
use AA\Later\BatchBudget;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Later\BatchStates;
use AA\Later\BatchStepResult;
use AA\Later\Toexecute;
use AA\Security\ViewSlices;
use AA\Util\Txt;
use AA_Credentials;
use AA_Log;
use AA_Property;

/**
 * The sending of one item to the readers of one list, frozen when it started (Recipients). Each reader
 * gets the mail once: before a mail goes, the record says whom it goes to (mark); right after it, what
 * came of it (cursor and outcomes). A step that dies in between leaves that reader uncertain - never
 * sent to again. A reader no mail went to (skipped, the mail not made) is written with the next record
 * of the step: a step that dies before it only decides them again.
 * The core writes position only at the end of a step, so the run keeps its own cursor: the next reader
 * to try is max(position i, cursor).
 */
class Run extends BatchRun
{
    public const SENDING = 'sending';
    public const ERROR   = 'error';
    public const DONE    = 'done';
    public const ENDED   = 'stopped';

    /** BatchFailed reason: the letter says it cannot be sent now, or no mail can be made - note says why */
    public const LETTER = 'LETTER';
    /** BatchFailed reason and BatchStepResult::$retry: the SMTP server failed before the mail went out */
    public const SMTP = 'SMTP';
    /** BatchFailed reason: the frozen readers are gone or not as many as the run started with */
    public const RECIPIENTS = 'RECIPIENTS';

    /** notes kept at most - the record is read whole on every write */
    public const MAX_NOTES = 500;
    /** mails not made in a row that stop the run: a broken template must not fail the whole list one by one */
    public const MAX_UNMADE = 10;

    /** what a mail being sent may take beyond the send: its mark before and its outcome after */
    private const RECORD_RESERVE = 30;
    /** a note is for people, not a log */
    private const NOTE_LENGTH = 300;
    /** the note of a mail not made - never the exception's text, which may carry data or a secret */
    private const NOT_MADE = 'The mail could not be rendered.';

    /** outcomes: the letter stored for each outcome but Sent */
    private const LETTERS = ['f' => Outcome::Failed, 's' => Outcome::Skipped, 'u' => Outcome::Uncertain];

    private const OWN = [
        'list', 'title', 'recipients', 'total', 'base', 'cursor', 'mark', 'outcomes', 'notes', 'note', 'retrying', 'unmade',
    ];

    /** the id of the Alerts collection the readers come from */
    protected $list = '';
    /** the name of the sending when it started - the overviews show it after the item changed or is gone */
    protected $title = '';
    /** the id of the Recipients list */
    protected $recipients = '';
    /** how many readers were frozen */
    protected $total = '';
    /** the base of the tracked addresses */
    protected $base = '';
    /** the index of the first reader not tried yet; only grows */
    protected $cursor = '';
    /** the index of the reader whose mail may be on its way; '' = none */
    protected $mark = '';
    /** JSON index => f(ailed), s(kipped) or u(ncertain) - complete, never cut; a reader below the cursor not in it was sent */
    protected $outcomes = '';
    /** JSON index => what the server or the letter said, at most MAX_NOTES */
    protected $notes = '';
    /** the last failure of the run, for the user */
    protected $note = '';
    /** what the retry waiting is for: '' none, retry (the server) or deferred (this reader) - Outcome values */
    protected $retrying = '';
    /** mails not made in a row */
    protected $unmade = '';

    /** @var list<array{int, Outcome, string}> what the step decided without a mail going out, not written yet */
    private array $held = [];

    public static function getClassProperties(): array
    {
        $props = parent::getClassProperties();
        foreach (self::OWN as $id) {
            $props[$id] = new AA_Property($id, $id, 'text', false, true, 'text');
        }
        return $props;
    }

    protected static function states(): BatchStates
    {
        return new BatchStates(self::SENDING, self::ERROR, self::DONE, self::ENDED);
    }

    public function selector(): string
    {
        return 'Mailbulk_' . $this->list . '_' . $this->subject;
    }

    /** an editor of all items of the item's slice, to stop it even after the list stopped belonging to the slice;
     *  once the item is gone, a superadmin. What sends again (Continue) checks the list too (Gate). */
    public function mayControl(string $uid): bool
    {
        return self::mayControlPair($uid, (string) $this->list, $this->itemSlice(), false);
    }

    /**
     * The rule of the rights to a sending: an editor of all items of the item's slice; with $needsRelation,
     * only when the list may send items of that slice. A gone item ($slice null) only a superadmin ends.
     */
    public static function mayControlPair(string $uid, string $list, ?string $slice, bool $needsRelation): bool
    {
        if (in_array($uid, ['', 'nobody'], true)) {
            return false;
        }
        class_exists(\AA_Perm::class);           // include/perm_core.php sets AA::$perm - a queue step may not have loaded it
        if ($slice === null) {
            return !$needsRelation && AA::$perm->isSuperadminUser($uid);
        }
        if (!AA::$perm->hasSlicePerm($uid, $slice, PS_EDIT_ALL_ITEMS)) {
            return false;
        }
        return !$needsRelation || static::listRelates($list, $slice);
    }

    /** the slice of an item; null when the item is gone */
    public static function sliceOfItem(string $itemId): ?string
    {
        $slices = ViewSlices::itemSlicesFromDb('l', [$itemId]);
        return $slices ? (string) reset($slices) : null;
    }

    /** the slices a step opens: of the readers of $list and the item's $slice
     *  @return list<string> */
    public static function slicesOf(string $list, ?string $slice): array
    {
        // not AA_Collection::getReaderSlice(): it needs the whole Alerts module loaded and reads the module, not just its id
        $readers = DB_AA::select1('slice_id', 'SELECT slice_id FROM `alerts_collection`', [['id', $list]]);
        $slices  = [is_string($readers) ? unpack_id($readers) : '', (string) $slice];
        return array_values(array_unique(array_filter($slices, is_long_id(...))));
    }

    /** a sending of an item deleted ends; one in the trash goes on to its problem() */
    protected function stillValid(): bool
    {
        return $this->itemSlice() !== null;
    }

    /** what renders the mails - a new one each step, so a step sends the item as it is now */
    protected function letter(): Letter
    {
        require_once constant('AA_BASE_PATH') . 'modules/alerts/alerts_sending.php';
        return new ItemLetter(new \AA_Collection((string) $this->list), (string) $this->subject, (string) $this->base);
    }

    protected function mailer(): Mailer
    {
        return new AaMailer();
    }

    /** the slices of the readers and of the item, open only during a step: a queue process runs other tasks after it
     *  @return list<string> */
    protected function slices(): array
    {
        return self::slicesOf((string) $this->list, $this->itemSlice());
    }

    /** the slice of the item sent; null when the item is gone */
    protected function itemSlice(): ?string
    {
        return self::sliceOfItem((string) $this->subject);
    }

    /** the list may send items of $slice */
    protected static function listRelates(string $list, string $slice): bool
    {
        require_once constant('AA_BASE_PATH') . 'modules/alerts/alerts_sending.php';
        return \AA_Collection::relatesToSlice($list, $slice);
    }

    /** a send started at the end of the budget must not be taken for a dead step */
    protected function overrun(): int
    {
        return $this->sendReserve() + self::RECORD_RESERVE;
    }

    protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult
    {
        return AA_Credentials::singleton()->withSlicesOpen($this->slices(), fn(): BatchStepResult => $this->workFrom($from, $budget));
    }

    /** Three steps without progress. A reader the server keeps refusing for now fails, the run goes on.
     *  After steps that died: a mark left is uncertain; with none, the reader at the cursor fails - the count
     *  of the core may include a server retry, yet ending in error would stop on that reader after every
     *  Continue, and a failed reader goes again with the next run. A server that keeps failing ends the run
     *  in error: Continue tries the same reader again. */
    protected function onRetriesExhausted(BatchStepResult $last): ?BatchStepResult
    {
        $deferred = ($last->retry === self::SMTP) && ((string) $this->retrying === Outcome::Deferred->value);
        if (!$deferred && ($last->retry !== self::STEP_DIED)) {
            return null;
        }
        $total = (int) $this->total;
        $at    = (int) ($last->position['i'] ?? 0);
        if (!$deferred && ((string) $this->mark === '')) {
            if (max($at, (int) $this->cursor) >= $total) {
                // every reader recorded: the next step finishes
                return $this->goesOn($total);
            }
        }
        $recorded = $this->recordResult(static function (self $run) use ($deferred, $at, $total): bool {
            if (!$deferred && $run->settle()) {
                return true;
            }
            $i = max($at, (int) $run->cursor);
            if (($i >= $total) || ((string) $run->mark !== '')) {
                return false;
            }
            $note = $deferred ? 'Temporarily refused: ' . (string) $run->note : 'The sending kept stopping on this reader.';
            self::record($run, $i, Outcome::Failed, $note);
            $run->retrying = '';
            return true;
        });
        return ($recorded === null) ? null : $this->goesOn($total);
    }

    /** The runs of $itemId to $list, the oldest first; those of other lists are not read.
     *  @return list<static> */
    public static function ofPair(string $itemId, string $list): array
    {
        $ids = array_keys(array_filter(static::recordsOfSubject($itemId, ['list']), static fn(array $r): bool => ($r['list'] ?? '') === $list));
        return array_reverse(array_values(array_filter(array_map(static fn($id): ?static => static::get((string) $id), $ids))));
    }

    /** @return array{sent: int, failed: int, skipped: int, uncertain: int} */
    public function outcomes(): array
    {
        $counts = array_count_values(self::map((string) $this->outcomes));
        $sum    = ['failed' => $counts['f'] ?? 0, 'skipped' => $counts['s'] ?? 0, 'uncertain' => $counts['u'] ?? 0];
        return ['sent' => (int) $this->cursor - array_sum($sum)] + $sum;
    }

    /** the readers who got the mail or may have got it - also the one it is going to right now
     *  @return list<string> long ids */
    public function delivered(): array
    {
        $ids  = Recipients::ids((string) $this->recipients);
        $not  = array_filter(self::map((string) $this->outcomes), static fn(string $o): bool => $o !== 'u');
        $upto = array_slice($ids, 0, (int) $this->cursor, true);
        if ((string) $this->mark !== '') {
            $upto[(int) $this->mark] = $ids[(int) $this->mark] ?? '';
        }
        return array_values(array_filter(array_diff_key($upto, $not), static fn(string $id): bool => $id !== ''));
    }

    /** What came of each reader, for figures over several runs: an Outcome value; '' = not tried yet in a
     *  run still sending or in error. A reader a finished or stopped run never tried is not in it.
     *  @return array<string, string> long id => outcome */
    public function readerOutcomes(): array
    {
        $map    = self::map((string) $this->outcomes);
        $cursor = (int) $this->cursor;
        $open   = in_array($this->state(), [self::SENDING, self::ERROR], true);
        $mark   = ((string) $this->mark === '') ? -1 : (int) $this->mark;
        $result = [];
        foreach (Recipients::ids((string) $this->recipients) as $i => $id) {
            if ($i < $cursor) {
                $result[$id] = (isset($map[$i]) ? self::LETTERS[$map[$i]] : Outcome::Sent)->value;
            } elseif ($open) {
                $result[$id] = '';
            } elseif ($i === $mark) {
                $result[$id] = Outcome::Uncertain->value;
            }
        }
        return $result;
    }

    /** @throws BatchFailed RECIPIENTS, LETTER, SMTP */
    private function workFrom(array $from, BatchBudget $budget): BatchStepResult
    {
        $total = (int) $this->total;
        $ids   = Recipients::ids((string) $this->recipients);
        if (($total < 1) || (count($ids) !== $total)) {
            // finished with none, or started again from nothing, it would leave them out or send them all again
            throw new BatchFailed(self::RECIPIENTS);
        }
        if (((string) $this->mark !== '') && ($this->recordResult(static fn(self $run): bool => $run->settle()) !== true)) {
            // Continue or another step took the run
            return $this->result($total);
        }
        $i = max((int) ($from['i'] ?? 0), (int) $this->cursor);
        if ($i >= $total) {
            return $this->result($total);
        }
        $letter  = $this->letter();
        $problem = $letter->problem();
        if ($problem !== null) {
            $this->recordResult(static function (self $run) use ($problem): bool {
                $run->note = self::short($problem);
                return true;
            });
            throw new BatchFailed(self::LETTER);
        }
        $mailer = $this->mailer();
        $retry  = '';
        while (($i < $total) && !$budget->spent()) {
            $goesOn = $this->sendTo($i, $ids[$i], $letter, $mailer);
            if ($goesOn !== true) {
                $retry = ($goesOn === null) ? self::SMTP : '';
                break;
            }
            ++$i;
        }
        if ($this->held) {
            $this->recorded(static fn(self $run): bool => true);
        }
        return $this->result($total, $retry);
    }

    /** The mail of reader $i: rendered and built first, then the mark, then sent.
     *  @return ?bool true = go on; false = the step ends; null = nothing went out, the step ends to try again later
     *  @throws BatchFailed LETTER no mail could be made MAX_UNMADE times in a row; SMTP the sending cannot go on */
    private function sendTo(int $i, string $readerId, Letter $letter, Mailer $mailer): ?bool
    {
        try {
            $message = $letter->messageFor($readerId);
            if ($message !== null) {
                $mailer->prepare($message);
            }
        } catch (\Throwable $e) {
            AA_Log::write(Toexecute::FAILED_EVENT, $this->selector(), Toexecute::failureParams($this->getId(), $e));
            return $this->notMade($i);
        }
        if ($message === null) {
            $this->held[] = [$i, Outcome::Skipped, ''];
            return true;
        }
        $going = $this->beforeSending(static function (self $run) use ($i): bool {
            // another step has the reader settled or on its way
            if (((string) $run->mark !== '') || ($i < (int) $run->cursor)) {
                return false;
            }
            $run->mark = (string) $i;
            return true;
        }, $this->sendReserve());
        if (!$going) {
            return false;
        }
        $outcome = $mailer->send();
        $detail  = $mailer->detail();
        if (in_array($outcome, [Outcome::Retry, Outcome::Deferred, Outcome::Halt], true)) {
            // nothing went out: the same reader is tried again - after a halt only once someone acts
            $cleared = $this->recorded(static function (self $run) use ($outcome, $detail): bool {
                $run->mark     = '';
                $run->note     = self::short($detail);
                $run->retrying = ($outcome === Outcome::Halt) ? '' : $outcome->value;
                return true;
            });
            if (($outcome === Outcome::Halt) && ($cleared === true)) {
                throw new BatchFailed(self::SMTP);
            }
            return ($cleared === true) ? null : false;
        }
        return $this->recorded(static function (self $run) use ($i, $outcome, $detail): bool {
            self::record($run, $i, $outcome, ($outcome === Outcome::Sent) ? '' : $detail);
            $run->mark     = '';
            $run->retrying = '';
            if ($outcome === Outcome::Sent) {
                // an older failure must not stand next to a later, unrelated one
                $run->note   = '';
                $run->unmade = '0';
            }
            return true;
        }) === true;
    }

    /** reader $i failed before a mail was made
     *  @return bool the step goes on */
    private function notMade(int $i): bool
    {
        $this->held[] = [$i, Outcome::Failed, self::NOT_MADE];
        $unmade       = (int) $this->unmade + count(array_filter($this->held, static fn(array $h): bool => $h[1] === Outcome::Failed));
        if ($unmade < self::MAX_UNMADE) {
            return true;
        }
        $written = $this->recorded(static function (self $run): bool {
            $run->note   = sprintf('The mail could not be made for %d readers in a row: check the item and the template of the list, then choose Continue.', self::MAX_UNMADE);
            $run->unmade = '0';
            return true;
        });
        if ($written === true) {
            throw new BatchFailed(self::LETTER);
        }
        return false;
    }

    /** beforeAction() that writes what the step holds unwritten too
     *  @param \Closure(self): bool $write */
    private function beforeSending(\Closure $write, int $reserve): bool
    {
        $going = $this->beforeAction($this->withHeld($write), $reserve);
        if ($going) {
            $this->held = [];
        }
        return $going;
    }

    /** recordResult() that writes what the step holds unwritten too
     *  @param \Closure(self): bool $write */
    private function recorded(\Closure $write): ?bool
    {
        $recorded = $this->recordResult($this->withHeld($write));
        if ($recorded !== null) {
            $this->held = [];
        }
        return $recorded;
    }

    /** $write after what the step holds - all of that is about readers before the one $write is about
     *  @param \Closure(self): bool $write
     *  @return \Closure(self): bool */
    private function withHeld(\Closure $write): \Closure
    {
        $held = $this->held;
        return static function (self $run) use ($held, $write): bool {
            foreach ($held as [$i, $outcome, $note]) {
                self::record($run, $i, $outcome, $note);
                // a reader held as failed is one whose mail was not made
                if ($outcome === Outcome::Failed) {
                    $run->unmade = (string) ((int) $run->unmade + 1);
                }
            }
            return $write($run);
        };
    }

    /** the longest a send and its records take: the lease must outlast them */
    private function sendReserve(): int
    {
        return $this->mailer()->maxSeconds() + self::RECORD_RESERVE;
    }

    /** reader $i's outcome on $run, the cursor past it */
    private static function record(self $run, int $i, Outcome $outcome, string $note): void
    {
        $letter = array_search($outcome, self::LETTERS, true);
        if ($letter !== false) {
            $map            = self::map((string) $run->outcomes);
            $map[$i]        = $letter;
            $run->outcomes  = json_encode($map, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
        }
        if ($note !== '') {
            $run->notes = self::noted((string) $run->notes, $i, $note);
        }
        $run->cursor = (string) max((int) $run->cursor, $i + 1);
    }

    /** the reader of a mark a dead step left is uncertain; false = no mark */
    private function settle(): bool
    {
        if ((string) $this->mark === '') {
            return false;
        }
        self::record($this, (int) $this->mark, Outcome::Uncertain, 'The sending stopped while this mail was being sent.');
        $this->mark = '';
        return true;
    }

    /** the way on from the cursor the core takes after the retries - never finished, that is the next step's */
    private function goesOn(int $total): BatchStepResult
    {
        return new BatchStepResult(['i' => (int) $this->cursor], false, done: (int) $this->cursor, total: $total);
    }

    /** where the run stands: at the cursor, the first reader without a record */
    private function result(int $total, string $retry = ''): BatchStepResult
    {
        $done = (int) $this->cursor;
        return new BatchStepResult(['i' => $done], ($retry === '') && ($done >= $total), done: $done, total: $total, retry: $retry);
    }

    /** @return array<int, string> index => a key of LETTERS
     *  @throws \JsonException a map damaged into no JSON - read as empty, it would send those readers again */
    private static function map(string $json): array
    {
        return ($json === '') ? [] : array_map('strval', (array) json_decode($json, true, 2, JSON_THROW_ON_ERROR));
    }

    private static function noted(string $json, int $i, string $text): string
    {
        $notes = ($json === '') ? [] : json_decode($json, true);
        $notes = is_array($notes) ? $notes : [];
        if ((count($notes) < self::MAX_NOTES) || isset($notes[$i])) {
            $notes[$i] = self::short($text);
        }
        return json_encode($notes, JSON_FORCE_OBJECT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private static function short(string $text): string
    {
        return Txt::utf8Cut(trim($text), self::NOTE_LENGTH);
    }
}
