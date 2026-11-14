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
use AA_Credentials;
use AA_Property;

/**
 * The sending of one item to the readers of one list, frozen when it started (Recipients). Each reader
 * gets the mail once: before a mail goes, the record says whom it goes to (mark); right after it, what
 * came of it (cursor and the lists). A step that dies in between leaves that reader uncertain - never
 * sent to again.
 * The core writes position only at the end of a step, so the run keeps its own cursor: the next reader
 * to try is max(position i, cursor).
 */
class Run extends BatchRun
{
    public const SENDING = 'sending';
    public const ERROR   = 'error';
    public const DONE    = 'done';
    public const ENDED   = 'stopped';

    /** BatchFailed reason: the letter says it cannot be sent now - note says why */
    public const LETTER = 'LETTER';
    /** BatchStepResult::$retry: the SMTP server failed before the mail went out */
    public const SMTP = 'SMTP';

    /** notes kept at most - the record is read whole on every write */
    public const MAX_NOTES = 500;

    /** the core's code of a step that kept dying */
    private const STEP_DIED = 'STEP_DIED';
    /** a note is for people, not a log */
    private const NOTE_LENGTH = 300;
    /** the round trips of one SMTP send (connect, EHLO, STARTTLS, EHLO, AUTH, MAIL, RCPT, DATA, QUIT),
     *  each waiting up to the timeout SmtpTransport takes */
    private const SMTP_ROUND_TRIPS = 9;
    /** SmtpTransport's timeout without AA_SMTP_TIMEOUT */
    private const SMTP_TIMEOUT = 15;

    private const OWN = [
        'list', 'title', 'recipients', 'total', 'base', 'cursor', 'mark', 'failed', 'skipped', 'uncertain', 'notes', 'note',
    ];

    /** the id of the Alerts collection the readers come from */
    protected $list = '';
    /** the name of the sending when it started - the overviews show it after the item changed or is gone */
    protected $title = '';
    /** the id of the Recipients list */
    protected $recipients = '';
    protected $total = '';
    /** the base of the tracked addresses */
    protected $base = '';
    /** the index of the first reader not tried yet; only grows */
    protected $cursor = '';
    /** the index of the reader whose mail may be on its way; '' = none */
    protected $mark = '';
    /** JSON lists of indexes - complete, never cut */
    protected $failed = '';
    protected $skipped = '';
    protected $uncertain = '';
    /** JSON index => what the server or the letter said, at most MAX_NOTES */
    protected $notes = '';
    /** the last failure of the run, for the user */
    protected $note = '';

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

    public function mayControl(string $uid): bool
    {
        $slice = $this->itemSlice();
        return ($slice !== null) && !in_array($uid, ['', 'nobody'], true) && AA::$perm->hasSlicePerm($uid, $slice, PS_EDIT_ALL_ITEMS);
    }

    /** what renders the mails */
    protected function letter(): Letter
    {
        throw new \LogicException('A sending has no letter yet');
    }

    protected function mailer(): Mailer
    {
        return new AaMailer();
    }

    /** the slices of the readers and of the item, open only during a step: a queue process runs other tasks after it
     *  @return list<string> */
    protected function slices(): array
    {
        $readers = DB_AA::select1('slice_id', 'SELECT slice_id FROM `alerts_collection`', [['id', (string) $this->list]]);
        $slices  = [is_string($readers) ? unpack_id($readers) : '', (string) $this->itemSlice()];
        return array_values(array_unique(array_filter($slices, is_long_id(...))));
    }

    /** the slice of the item sent; null when the item is gone */
    protected function itemSlice(): ?string
    {
        $content = GetItemContent(new \zids((string) $this->subject, 'l'), false, true, ['slice_id........']);
        $slice   = $content ? (reset($content)['u_slice_id......'][0]['value'] ?? null) : null;
        return is_string($slice) ? $slice : null;
    }

    /** a send may take every round trip with its full timeout: the lease must outlast it */
    protected function overrun(): int
    {
        return self::sendSeconds() + 30;
    }

    protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult
    {
        return AA_Credentials::singleton()->withSlicesOpen($this->slices(), fn(): BatchStepResult => $this->workFrom($from, $budget));
    }

    /** A reader whose mail failed, after the step died on it three times; a mark left is uncertain.
     *  A server that keeps failing ends the run in error: Continue tries the same reader again. */
    protected function onRetriesExhausted(BatchStepResult $last): ?BatchStepResult
    {
        if ($last->retry !== self::STEP_DIED) {
            return null;
        }
        $total    = $this->readerCount();
        $recorded = $this->recordResult(static function (self $run) use ($total): bool {
            if (!$run->settle()) {
                $i = (int) $run->cursor;
                if ($i >= $total) {
                    return false;
                }
                $run->failed = self::with((string) $run->failed, $i);
                $run->notes  = self::noted((string) $run->notes, $i, 'The sending stopped on this reader three times.');
                $run->cursor = (string) ($i + 1);
            }
            return true;
        });
        return ($recorded === null) ? null : $last->withPosition(['i' => (int) $this->cursor]);
    }

    /** @return array{sent: int, failed: int, skipped: int, uncertain: int} */
    public function outcomes(): array
    {
        $failed    = count(self::indexes((string) $this->failed));
        $skipped   = count(self::indexes((string) $this->skipped));
        $uncertain = count(self::indexes((string) $this->uncertain));
        return ['sent' => (int) $this->cursor - $failed - $skipped - $uncertain, 'failed' => $failed, 'skipped' => $skipped, 'uncertain' => $uncertain];
    }

    /** the readers who got the mail or may have got it - also the one it is going to right now
     *  @return list<string> long ids */
    public function delivered(): array
    {
        $ids  = Recipients::ids((string) $this->recipients);
        $not  = array_flip([...self::indexes((string) $this->failed), ...self::indexes((string) $this->skipped)]);
        $upto = array_slice($ids, 0, (int) $this->cursor, true);
        if ((string) $this->mark !== '') {
            $upto[(int) $this->mark] = $ids[(int) $this->mark] ?? '';
        }
        return array_values(array_filter(array_diff_key($upto, $not), static fn(string $id): bool => $id !== ''));
    }

    private function workFrom(array $from, BatchBudget $budget): BatchStepResult
    {
        $ids   = Recipients::ids((string) $this->recipients);
        $total = count($ids);
        if (((string) $this->mark !== '') && ($this->recordResult(static fn(self $run): bool => $run->settle()) === null)) {
            // Continue or another step took the run
            return $this->result($from, $total);
        }
        $i = max((int) ($from['i'] ?? 0), (int) $this->cursor);
        if ($i >= $total) {
            return $this->result(['i' => $i], $total);
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
        while (($i < $total) && !$budget->spent()) {
            $goesOn = $this->sendTo($i, $ids[$i], $letter, $mailer);
            if ($goesOn !== true) {
                return $this->result(['i' => (int) $this->cursor], $total, ($goesOn === false) ? '' : self::SMTP);
            }
            $i = (int) $this->cursor;
        }
        return $this->result(['i' => $i], $total);
    }

    /** The mail of reader $i: rendered and built first, then the mark, then sent.
     *  @return ?bool true = go on; false = the step ends; null = the server failed before the mail went out */
    private function sendTo(int $i, string $readerId, Letter $letter, Mailer $mailer): ?bool
    {
        try {
            $message = $letter->messageFor($readerId);
            if ($message === null) {
                return $this->recorded($i, Outcome::Skipped, '');
            }
            $mailer->prepare($message);
        } catch (\Throwable $e) {
            return $this->recorded($i, Outcome::Failed, $e->getMessage());
        }
        $going = $this->beforeAction(static function (self $run) use ($i): bool {
            // another step has the reader settled or on its way
            if (((string) $run->mark !== '') || ($i < (int) $run->cursor)) {
                return false;
            }
            $run->mark = (string) $i;
            return true;
        }, self::sendSeconds());
        if (!$going) {
            return false;
        }
        $outcome = $mailer->send();
        if (in_array($outcome, [Outcome::Retry, Outcome::Deferred, Outcome::Halt], true)) {
            $detail = $mailer->detail();
            // nothing went out: the same reader is tried again
            $cleared = $this->recordResult(static function (self $run) use ($detail): bool {
                $run->mark = '';
                $run->note = self::short($detail);
                return true;
            });
            return ($cleared === true) ? null : false;
        }
        return $this->recorded($i, $outcome, ($outcome === Outcome::Sent) ? '' : $mailer->detail());
    }

    /** what came of reader $i, the cursor past it
     *  @return bool the step goes on */
    private function recorded(int $i, Outcome $outcome, string $note): bool
    {
        return $this->recordResult(static function (self $run) use ($i, $outcome, $note): bool {
            match ($outcome) {
                Outcome::Failed    => $run->failed = self::with((string) $run->failed, $i),
                Outcome::Skipped   => $run->skipped = self::with((string) $run->skipped, $i),
                Outcome::Uncertain => $run->uncertain = self::with((string) $run->uncertain, $i),
                default            => null,
            };
            if ($note !== '') {
                $run->notes = self::noted((string) $run->notes, $i, $note);
            }
            $run->cursor = (string) max((int) $run->cursor, $i + 1);
            $run->mark   = '';
            return true;
        }) === true;
    }

    /** the reader of a mark a dead step left is uncertain; false = no mark */
    private function settle(): bool
    {
        if ((string) $this->mark === '') {
            return false;
        }
        $i               = (int) $this->mark;
        $this->uncertain = self::with((string) $this->uncertain, $i);
        $this->notes     = self::noted((string) $this->notes, $i, 'The sending stopped while this mail was being sent.');
        $this->cursor    = (string) max((int) $this->cursor, $i + 1);
        $this->mark      = '';
        return true;
    }

    private function result(array $position, int $total, string $retry = ''): BatchStepResult
    {
        $done = (int) $this->cursor;
        return new BatchStepResult($position, ($retry === '') && ($done >= $total), done: $done, total: $total, retry: $retry);
    }

    private function readerCount(): int
    {
        return count(Recipients::ids((string) $this->recipients));
    }

    /** the longest one send may take */
    private static function sendSeconds(): int
    {
        $timeout = defined('AA_SMTP_TIMEOUT') ? max(1, (int) constant('AA_SMTP_TIMEOUT')) : self::SMTP_TIMEOUT;
        return self::SMTP_ROUND_TRIPS * $timeout;
    }

    /** @return list<int> */
    private static function indexes(string $json): array
    {
        $list = ($json === '') ? [] : json_decode($json, true);
        return is_array($list) ? array_values(array_map('intval', $list)) : [];
    }

    private static function with(string $json, int $i): string
    {
        $list = self::indexes($json);
        if (!in_array($i, $list, true)) {
            $list[] = $i;
        }
        return json_encode($list, JSON_THROW_ON_ERROR);
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
        return mb_substr(mb_scrub(trim($text), 'UTF-8'), 0, self::NOTE_LENGTH);
    }
}
