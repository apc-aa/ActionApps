<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA_Module_Alerts;

/**
 * The mailings of the list of one Alerts module: every item the list was sent, its runs together in
 * one row. Reads the records of the runs only, never the runs. Rights are not checked here - the
 * caller has.
 */
class Overview
{
    /** @var ?array<string, array{item: string, title: string, date: int, state: string}> item => row, read once */
    private ?array $rows = null;

    final protected function __construct(private readonly string $list) {}

    /** null when the module is no Alerts module or has no list */
    public static function forModule(string $moduleId): ?static
    {
        // the type first: a letter of the Alerts rights means something else in another module
        if (!is_long_id($moduleId) || (AA_Module_Alerts::getOwnModule($moduleId) === null)) {
            return null;
        }
        $list = static::listOf($moduleId);
        return ($list === '') ? null : new static($list);
    }

    /** the id of the list (Alerts collection) */
    public function list(): string
    {
        return $this->list;
    }

    /** the mailings: the items with at least one run of the list */
    public function count(): int
    {
        return count($this->rows());
    }

    /** @return list<array{item: string, title: string, date: int, state: string}> the newest first, $page counted from 1;
     *          the title and state of the newest run, date = its start */
    public function page(int $page, int $size = 50): array
    {
        return array_values(array_slice($this->rows(), max(0, $page - 1) * max(1, $size), max(1, $size)));
    }

    /** the list sent $itemId - an id from a request shows nothing without it */
    public function has(string $itemId): bool
    {
        return isset($this->rows()[strtolower($itemId)]);
    }

    // ------------------------------------------------------------ what tests replace

    /** @return class-string<Run> */
    protected static function runClass(): string
    {
        return Run::class;
    }

    /** the list of an Alerts module; '' = none */
    protected static function listOf(string $moduleId): string
    {
        require_once constant('AA_BASE_PATH') . 'modules/alerts/util.php';
        $collection = \GetCollection($moduleId);
        return is_array($collection) ? (string) $collection['id'] : '';
    }

    // ------------------------------------------------------------

    /** @return array<string, array{item: string, title: string, date: int, state: string}> */
    private function rows(): array
    {
        if ($this->rows === null) {
            $this->rows = [];
            // the newest run of an item comes first, so it gives the row
            foreach (static::runClass()::recordsOfList($this->list) as $record) {
                $item               = $record['subject'];
                $this->rows[$item] ??= ['item' => $item, 'title' => $record['title'], 'date' => (int) $record['started'], 'state' => $record['state']];
            }
        }
        return $this->rows;
    }
}
