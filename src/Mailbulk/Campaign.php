<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;
use AA\Later\Toexecute;
use AA_Log;

/**
 * The sending of one item to one list: all its runs together. A later run (Continue, new readers)
 * goes only to the readers no earlier run sent the mail to or may have sent it to. Runs are never
 * deleted - they hold the figures of old sendings and whom the next run leaves out.
 */
class Campaign
{
    /** start() refusals, BatchFailed::$reason */
    public const PROBLEM = 'PROBLEM';
    public const ACTIVE  = 'ACTIVE';
    public const BUSY    = 'BUSY';
    public const NOBODY  = 'NOBODY';
    public const EMPTY   = 'EMPTY';
    public const LOCK    = 'LOCK';
    public const RENDER  = 'RENDER';

    /** the overviews keep the name of a sending this long */
    private const TITLE_LENGTH = 250;
    /** readers the test mail is made for at most, until one is still valid */
    private const TEST_READERS = 5;

    /** @var ?list<Run> read once - a page asks for state, figures and the runs */
    private ?array $runs = null;

    final private function __construct(private readonly string $list, private readonly string $itemId) {}

    /** @throws \InvalidArgumentException $itemId is no long id */
    public static function of(string $list, string $itemId): static
    {
        // the runs are found by the item id as stored: another case would be another sending
        $itemId = strtolower($itemId);
        if (!preg_match('/^[0-9a-f]{32}\z/', $itemId)) {
            throw new \InvalidArgumentException('A sending is of an item given by its long id');
        }
        return new static($list, $itemId);
    }

    /** @return list<Run> the oldest first */
    public function runs(): array
    {
        return $this->runs ??= static::runClass()::ofPair($this->itemId, $this->list);
    }

    /** the run not finished yet - sending, or in error till Continue */
    public function active(): ?Run
    {
        return self::activeOf($this->runs());
    }

    /** the state of the newest run; '' = never sent */
    public function state(): string
    {
        $runs = $this->runs();
        return $runs ? end($runs)->state() : '';
    }

    /**
     * The figures of the sending, each reader counted once over all runs. A reader frozen again into a
     * later run (failed or skipped before) counts by what came of it there, or only in the total while
     * that run has not tried it yet. total = the readers a finished or stopped run tried and all readers
     * of an unfinished one.
     * @return array{total: int, sent: int, failed: int, skipped: int, uncertain: int, date: int}
     *         date = when the last run done finished, 0 = none is
     */
    public function counts(): array
    {
        $latest = [];
        $date   = 0;
        foreach ($this->runs() as $run) {
            $latest = $run->readerOutcomes() + $latest;
            if ($run->state() === Run::DONE) {
                $date = max($date, (int) $run->getProperty('finished'));
            }
        }
        $tally = array_count_values($latest);
        $sum   = ['total' => count($latest)];
        foreach ([Outcome::Sent, Outcome::Failed, Outcome::Skipped, Outcome::Uncertain] as $outcome) {
            $sum[$outcome->value] = $tally[$outcome->value] ?? 0;
        }
        return $sum + ['date' => $date];
    }

    /** @return list<string> the readers of $letter no run of the pair has sent the mail to, nor may have */
    public function pending(Letter $letter): array
    {
        return $this->pendingOf($letter, $this->runs());
    }

    /**
     * A new run to the pending readers, frozen and queued in one transaction, under the lock of the pair
     * held till after the commit - two clicks never freeze one reader twice. No rights checked: the caller has.
     * @param string $base the base of the tracked addresses
     * @throws BatchFailed PROBLEM the letter cannot be sent; ACTIVE a run is not finished, or a step of an
     *                     ended one may still be sending; BUSY another request is starting it; LOCK the
     *                     database gives no lock; NOBODY no one is pending; EMPTY the mail of the first
     *                     valid one has no subject or no body; RENDER making it failed
     */
    public function start(Letter $letter, string $uid, string $base = ''): Run
    {
        if (DB_AA::inTransaction()) {
            throw new \LogicException('A sending starts outside any transaction');
        }
        if ($letter->problem() !== null) {
            throw new BatchFailed(self::PROBLEM);
        }
        $holder = null;
        if (!static::lockPair($this->list, $this->itemId, $holder)) {
            throw new BatchFailed(($holder !== null) ? self::BUSY : self::LOCK);
        }
        try {
            // what others started before the lock
            $this->runs = null;
            $runs       = $this->runs();
            $class      = static::runClass();
            $ours       = array_map(static fn(Run $run): string => (string) $run->getId(), $runs);
            if (self::activeOf($runs) || array_intersect($class::leasedOf($this->itemId), $ours)) {
                throw new BatchFailed(self::ACTIVE);
            }
            $pending = $this->pendingOf($letter, $runs);
            if (!$pending) {
                throw new BatchFailed(self::NOBODY);
            }
            if (!self::hasContent($this->testMessage($letter, $pending))) {
                throw new BatchFailed(self::EMPTY);
            }
            $this->runs = null;
            return DB_AA::transaction(fn(): Run => $class::start([
                'subject'    => $this->itemId,
                'user'       => $uid,
                'position'   => ['i' => 0],
                'list'       => $this->list,
                'title'      => mb_substr($letter->title(), 0, self::TITLE_LENGTH),
                'recipients' => Recipients::freeze($this->itemId, $pending),
                'total'      => (string) count($pending),
                'base'       => $base,
                'cursor'     => '0',
            ]));
        } finally {
            static::unlockPair($this->list, $this->itemId);
        }
    }

    // ------------------------------------------------------------ what tests replace

    /** @return class-string<Run> */
    protected static function runClass(): string
    {
        return Run::class;
    }

    /** @param ?int $holder when not got: the connection that holds it, null = no lock at all (DB_AA::lock())
     *  @return bool got it - unlockPair() then */
    protected static function lockPair(string $list, string $itemId, ?int &$holder = null): bool
    {
        return DB_AA::lock(self::lockName($list, $itemId), 0, $holder);
    }

    protected static function unlockPair(string $list, string $itemId): void
    {
        DB_AA::unlock(self::lockName($list, $itemId));
    }

    private static function lockName(string $list, string $itemId): string
    {
        return "mailbulk:$list:$itemId";
    }

    /** @param list<Run> $runs */
    private static function activeOf(array $runs): ?Run
    {
        foreach ($runs as $run) {
            if (in_array($run->state(), [Run::SENDING, Run::ERROR], true)) {
                return $run;
            }
        }
        return null;
    }

    /** the mail of the first of $pending still valid - a reader who left since the selection has none
     *  @param list<string> $pending
     *  @throws BatchFailed RENDER the letter failed to make it; the log has where */
    private function testMessage(Letter $letter, array $pending): ?Message
    {
        foreach (array_slice($pending, 0, self::TEST_READERS) as $readerId) {
            try {
                $message = $letter->messageFor($readerId, true);
            } catch (\Throwable $e) {
                // never the text, which may carry data or a secret
                AA_Log::write(Toexecute::FAILED_EVENT, "Mailbulk_{$this->list}_{$this->itemId}", Toexecute::failureParams($this->itemId, $e));
                throw new BatchFailed(self::RENDER);
            }
            if ($message !== null) {
                return $message;
            }
        }
        return null;
    }

    /** @param list<Run> $runs
     *  @return list<string> */
    private function pendingOf(Letter $letter, array $runs): array
    {
        $reached = array_flip(array_merge(...array_map(static fn(Run $run): array => $run->delivered(), $runs)));
        return array_values(array_filter($letter->selection(), static fn(string $id): bool => !isset($reached[$id])));
    }

    /** a template that renders to nothing must not go to thousands; the text of the mail counts as the
     *  plain-text part shows it (no head, style nor script), and an image alone is content */
    private static function hasContent(?Message $message): bool
    {
        if (($message === null) || (trim($message->subject) === '')) {
            return false;
        }
        require_once constant('AA_BASE_PATH') . 'include/mail.php';
        $text = trim(html2text($message->html), " \t\n\r\0\x0B\u{a0}");
        return ($text !== '') || (preg_match('/<img\b/i', $message->html) === 1);
    }
}
