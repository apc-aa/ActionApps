<?php

declare(strict_types=1);

namespace AA\Mailbulk;

/**
 * The mailings of one list: every item the list was sent, its runs together in one row. A row is read
 * from the records of the runs; only an item sent by more runs reads its runs, to count each reader
 * once. Rights are not checked here - the caller has.
 */
class Overview
{
    /** @var ?array<string, array{item: string, titles: list<string>, date: int, state: string, runs: int,
     *        figures: array{total: int, sent: int, failed: int, skipped: int, uncertain: int}}> item => row, read once */
    private ?array $rows = null;
    /** @var array<string, Campaign> item => the sending read for its row */
    private array $campaigns = [];

    /** @param string $list the id of the list (Alerts collection) */
    public function __construct(private readonly string $list) {}

    /** the mailings: the items with at least one run of the list */
    public function count(): int
    {
        return count($this->rows());
    }

    /** @return list<array{item: string, title: string, date: int, state: string,
     *          counts: array{total: int, sent: int, failed: int, skipped: int, uncertain: int}}>
     *          the newest first, $page counted from 1; the state of the newest run, date = its start */
    public function page(int $page, int $size = 50): array
    {
        $rows = [];
        foreach (array_slice($this->rows(), max(0, $page - 1) * max(1, $size), max(1, $size)) as $row) {
            $rows[] = [
                'item'   => $row['item'],
                'title'  => Run::titleOf($row['titles'], $row['item']),
                'date'   => $row['date'],
                'state'  => $row['state'],
                'counts' => ($row['runs'] > 1) ? array_diff_key($this->campaign($row['item'])->counts(), ['date' => true]) : $row['figures'],
            ];
        }
        return $rows;
    }

    /** the list sent $itemId - an id from a request shows nothing without it */
    public function has(string $itemId): bool
    {
        return isset($this->rows()[strtolower($itemId)]);
    }

    /** the sending of $itemId, the one its row has read when it did */
    public function campaign(string $itemId): Campaign
    {
        $itemId = strtolower($itemId);
        return $this->campaigns[$itemId] ??= $this->newCampaign($itemId);
    }

    // ------------------------------------------------------------ what tests replace

    /** @return class-string<Run> */
    protected static function runClass(): string
    {
        return Run::class;
    }

    protected function newCampaign(string $itemId): Campaign
    {
        return Campaign::of($this->list, $itemId);
    }

    // ------------------------------------------------------------

    /** @return array<string, array{item: string, titles: list<string>, date: int, state: string, runs: int,
     *          figures: array{total: int, sent: int, failed: int, skipped: int, uncertain: int}}> */
    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            // the newest run of an item comes first, so it gives the date and the state
            foreach (static::runClass()::recordsOfList($this->list) as $record) {
                $item = $record['subject'];
                if (isset($this->rows[$item])) {
                    $this->rows[$item]['titles'][] = $record['title'];
                    ++$this->rows[$item]['runs'];
                    continue;
                }
                $this->rows[$item] = ['item' => $item, 'titles' => [$record['title']], 'date' => $record['started'], 'state' => $record['state'], 'runs' => 1, 'figures' => $record['figures']];
            }
        }
        return $this->rows;
    }
}
