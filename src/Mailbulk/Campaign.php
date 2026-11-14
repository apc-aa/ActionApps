<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;

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

    /** the overviews keep the name of a sending this long */
    private const TITLE_LENGTH = 250;

    final private function __construct(private readonly string $list, private readonly string $itemId) {}

    public static function of(string $list, string $itemId): static
    {
        return new static($list, $itemId);
    }

    /** @return list<Run> the oldest first */
    public function runs(): array
    {
        $class = static::runClass();
        $runs  = array_filter($class::ofSubject($this->itemId, PHP_INT_MAX), fn(Run $run): bool => (string) $run->getProperty('list') === $this->list);
        return array_reverse(array_values($runs));
    }

    /** the run not finished yet - sending, or in error till Continue */
    public function active(): ?Run
    {
        foreach ($this->runs() as $run) {
            if (in_array($run->state(), [Run::SENDING, Run::ERROR], true)) {
                return $run;
            }
        }
        return null;
    }

    /** the state of the newest run; '' = never sent */
    public function state(): string
    {
        $runs = $this->runs();
        return $runs ? end($runs)->state() : '';
    }

    /** @return array{total: int, sent: int, failed: int, skipped: int, uncertain: int, date: int}
     *          over all runs; date = when the last run done finished, 0 = none is */
    public function counts(): array
    {
        $sum = ['total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'uncertain' => 0, 'date' => 0];
        foreach ($this->runs() as $run) {
            $sum['total'] += (int) $run->getProperty('total');
            foreach ($run->outcomes() as $outcome => $n) {
                $sum[$outcome] += $n;
            }
            if ($run->state() === Run::DONE) {
                $sum['date'] = max($sum['date'], (int) $run->getProperty('finished'));
            }
        }
        return $sum;
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
     *                     ended one may still be sending; BUSY another request is starting it; NOBODY no
     *                     one is pending; EMPTY the mail of the first one has no subject or no body
     */
    public function start(Letter $letter, string $uid, string $base = ''): Run
    {
        if (DB_AA::inTransaction()) {
            throw new \LogicException('A sending starts outside any transaction');
        }
        if ($letter->problem() !== null) {
            throw new BatchFailed(self::PROBLEM);
        }
        if (!static::lockPair($this->list, $this->itemId)) {
            throw new BatchFailed(self::BUSY);
        }
        try {
            $runs  = $this->runs();
            $class = static::runClass();
            $ours  = array_map(static fn(Run $run): string => (string) $run->getId(), $runs);
            if ($this->active() || array_intersect($class::leasedOf($this->itemId), $ours)) {
                throw new BatchFailed(self::ACTIVE);
            }
            $pending = $this->pendingOf($letter, $runs);
            if (!$pending) {
                throw new BatchFailed(self::NOBODY);
            }
            if (!self::hasContent($letter->messageFor($pending[0], true))) {
                throw new BatchFailed(self::EMPTY);
            }
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

    /** @return bool got it - unlockPair() then; false also when the database gives no lock at all */
    protected static function lockPair(string $list, string $itemId): bool
    {
        return DB_AA::lock(self::lockName($list, $itemId));
    }

    protected static function unlockPair(string $list, string $itemId): void
    {
        DB_AA::unlock(self::lockName($list, $itemId));
    }

    private static function lockName(string $list, string $itemId): string
    {
        return "mailbulk:$list:$itemId";
    }

    /** @param list<Run> $runs
     *  @return list<string> */
    private function pendingOf(Letter $letter, array $runs): array
    {
        $reached = array_flip(array_merge(...array_map(static fn(Run $run): array => $run->delivered(), $runs)));
        return array_values(array_filter($letter->selection(), static fn(string $id): bool => !isset($reached[$id])));
    }

    /** a template that renders to nothing must not go to thousands; an image alone is content */
    private static function hasContent(?Message $message): bool
    {
        if ($message === null) {
            return false;
        }
        $text = trim(html_entity_decode(strip_tags($message->html, '<img>'), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\u{a0}");
        return (trim($message->subject) !== '') && ($text !== '');
    }
}
