<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/**
 */
class MultivalueDuplicates extends AbstractOptimize
{
    /** A duplicate needs two rows of one item field. Those pairs come from the index, so the
     *  costly GROUP BY over `text` gets only their rows (a small part of `content`), not all.
     *  IN never matches NULL, so rows with a NULL key are kept (the columns are still nullable
     *  on some live DBs, e.g. aadb_utf) - the result stays exactly that of the plain GROUP BY. */
    private const IN_MULTIROW_FIELD = "((`item_id`, `field_id`) IN (SELECT `item_id`, `field_id` FROM `content` GROUP BY `item_id`, `field_id` HAVING count(*) > 1) OR `item_id` IS NULL OR `field_id` IS NULL)";

    /** a row GetItemContent() reads as text: the flag, or any text (rows written before 2004 have no flag) */
    private const TEXT_ROW   = "(COALESCE(`flag` & " . FLAG_TEXT_STORED . ", 0) <> 0 OR COALESCE(LENGTH(`text`), 0) > 0)";
    private const NUMBER_ROW = "(NOT " . self::TEXT_ROW . ")";

    /** one commit per DELETE waits for the disk (~3 ms each); a transaction holds the locks of its groups */
    private const GROUPS_PER_TRANSACTION = 100;

    /** shown on State Check - see AbstractOptimize::CHECK_* */
    public static function types(): array
    {
        return [self::CHECK_SLOW];
    }

    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Multivalue Duplicates");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Removes duplicate values in multivalue text fields");
    }

    /** Test function
     * tests for duplicate entries
     * @return bool
     */
    public function test(): bool
    {
        $err_text = DB_AA::select([], self::textDuplicatesSql());

        if ($err_text) {
            $this->message(_m('%1 duplicates found in text fields', [count($err_text)]));
            foreach ($err_text as $wrong) {
                $this->message(_m('Duplicates (%4) in item %1 - field %2 - value %3', [
                    bin2hex($wrong['item_id'] ?? ''),
                    safe($wrong['field_id']),
                    safe($wrong['text']),   // editor input, the report is HTML
                    $wrong['cnt'],
                ]));
            }
        }

        $err_num = self::numberDuplicates();

        if ($err_num) {
            $this->message(_m('%1 duplicates found in numeric fields', [count($err_num)]));
            foreach ($err_num as $wrong) {
                $this->message(_m('Duplicates (%4) in item %1 - field %2 - value %3 - numeric', [
                    bin2hex($wrong['item_id'] ?? ''),
                    safe($wrong['field_id']),
                    $wrong['number'],
                    $wrong['cnt'],
                ]));
            }
        }

        if (!$err_text && !$err_num) {
            $this->message(_m('No duplicates found, hurray!'));
        } elseif (!self::innoDb()) {
            $this->message(self::noInnoDb());
        }
        return !$err_text && !$err_num;
    }

    /** no repair on a `content` without InnoDB (see repair()) */
    public function hasAction(string $action): bool
    {
        return parent::hasAction($action) && (($action !== 'repair') || self::innoDb());
    }

    /** Keeps one row of each group - the rows are the same, so no value is lost
     * @return bool
     */
    public function repair(): bool
    {
        if (!self::innoDb()) {
            $this->message(self::noInnoDb());
            return false;
        }
        $err_text = DB_AA::select([], self::textDuplicatesSql());
        $err_num  = self::numberDuplicates();
        if (!$err_text && !$err_num) {
            $this->message(_m('No duplicates found, hurray!'));
            return true;
        }
        if ($err_text) {
            $this->message(_m('%1 duplicates found in text fields', [count($err_text)]));
        }
        if ($err_num) {
            $this->message(_m('%1 duplicates found in numeric fields', [count($err_num)]));
        }
        $deleted = 0;
        try {
            $done = self::deleteCopies([...array_map(self::textWhere(...), $err_text), ...array_map(self::numberWhere(...), $err_num)], $deleted);
        } catch (\RuntimeException $e) {          // a COMMIT the server refused - its chunk is rolled back
            $done = false;
            $why  = $e->getMessage();
        }
        if (!$done) {
            $this->message(_m('The repair stopped after %1 rows deleted: %2', [$deleted, safe($why ?? _m('no database transaction could be started'))]));
            return false;
        }
        $this->message(_m('%1 rows deleted', [$deleted]));
        return true;
    }

    /** The repair counts the copies again under a lock (deleteCopies()) - MyISAM has none, and a save between
     *  the count and the DELETE could take the last copy */
    private static function innoDb(): bool
    {
        return DB_AA::select1('ENGINE', 'SELECT ENGINE FROM information_schema.TABLES', [['TABLE_SCHEMA', constant('DB_NAME')], ['TABLE_NAME', 'content']]) === 'InnoDB';
    }

    private static function noInnoDb(): string
    {
        return _m('The content table is not InnoDB, so the duplicates cannot be repaired here. Run "%1" first.', [Convert2innodb::name()]);
    }

    /** A row stored twice: the same item field, position (`number`), flag and text byte for byte (SHA2 - the
     *  collation takes `Praha` for `praha `). A value repeated at another position is left: the positions pair
     *  the translations of a value and empty values pad them. The report gets the start of the text. */
    public static function textDuplicatesSql(): string
    {
        return "SELECT `item_id`, `field_id`, `number`, `flag`, SHA2(`text`, 256) AS `text_hash`, MIN(LEFT(`text`, 200)) AS `text`, count(*) AS `cnt` FROM `content` WHERE "
            . self::TEXT_ROW . " AND " . self::IN_MULTIROW_FIELD . " GROUP BY `item_id`, `field_id`, `number`, `flag`, `text_hash` HAVING `cnt` > 1";
    }

    /** The groups numberDuplicates() counts again. No text column here: with it InnoDB would read every long
     *  text of the table, and the plain GROUP BY is cheaper than the pair lookup. */
    public static function numberCandidatesSql(): string
    {
        return "SELECT `item_id`, `field_id`, `number`, `flag`, count(*) AS `cnt` FROM `content` WHERE COALESCE(`flag` & " . FLAG_TEXT_STORED . ", 0) = 0 GROUP BY `item_id`, `field_id`, `number`, `flag` HAVING `cnt` > 1";
    }

    /** A number stored twice in an item field with the same flag - a number has no position. A candidate may
     *  hold text rows without the flag, so its rows are counted again, numbers only.
     * @return list<array<string, mixed>>
     */
    public static function numberDuplicates(): array
    {
        $dups = [];
        foreach (DB_AA::select([], self::numberCandidatesSql()) as $dup) {
            $dup['cnt'] = DB_AA::count('content', self::numberWhere($dup));
            if ($dup['cnt'] > 1) {
                $dups[] = $dup;
            }
        }
        return $dups;
    }

    /** the DB_AA::makeWhere() conditions of the rows of a textDuplicatesSql() group
     * @param array<string, mixed> $dup
     * @return list<array{string, mixed, string}>
     */
    public static function textWhere(array $dup): array
    {
        // the flag and the text keep numeric rows out: the flag has FLAG_TEXT_STORED, or the text is not empty
        return [...self::key($dup), ($dup['text_hash'] === null) ? ['text', '', 'ISNULL'] : ['SHA2(`text`, 256)', $dup['text_hash'], 's']];
    }

    /** as textWhere(), of a numberCandidatesSql() group - its text rows without the flag left out
     * @param array<string, mixed> $dup
     * @return list<array{string, mixed, string}>
     */
    public static function numberWhere(array $dup): array
    {
        return [...self::key($dup), [self::NUMBER_ROW, 1, 'i']];
    }

    /** the ids by their bytes (`b`, exact): an item id DbBinaryTrailingZeros shortened is no long id for `l`;
     *  a NULL matches NULL
     * @param array<string, mixed> $dup
     * @return list<array{string, mixed, string}>
     */
    private static function key(array $dup): array
    {
        $equal = static fn(string $column, mixed $value, string $type): array => ($value === null) ? [$column, '', 'ISNULL'] : [$column, $value, $type];
        return [$equal('item_id', $dup['item_id'], 'b'), $equal('field_id', $dup['field_id'], 'b'), $equal('number', $dup['number'], 'i'), $equal('flag', $dup['flag'], 'i')];
    }

    /** Deletes all rows of each group but one. The rows are counted again, locked: an item saved since the
     *  scan may hold fewer copies now, and the last one must stay. A refused statement ends the request
     *  (Halt_On_Error), a refused COMMIT throws; the transaction of the chunk rolls back either way.
     * @param list<list<array{string, mixed, string}>> $wheres
     * @param int $deleted the rows deleted, also when it stops
     * @return bool false: no transaction could be started
     */
    private static function deleteCopies(array $wheres, int &$deleted): bool
    {
        foreach (array_chunk($wheres, self::GROUPS_PER_TRANSACTION) as $chunk) {
            $rows = DB_AA::transaction(static function () use ($chunk): ?int {
                if (!DB_AA::inTransaction()) {          // DB_AA::transaction() runs the block without one when it cannot start it
                    return null;
                }
                $rows = 0;
                foreach ($chunk as $where) {
                    $sql_where = DB_AA::makeWhere($where);
                    $copies    = (int) (DB_AA::select('cnt', "SELECT COUNT(*) AS `cnt` FROM `content` $sql_where FOR UPDATE")[0] ?? 0);
                    if ($copies > 1) {
                        $rows += (int) DB_AA::sql("DELETE FROM `content` $sql_where LIMIT " . ($copies - 1));
                    }
                }
                return $rows;
            });
            if ($rows === null) {
                return false;
            }
            $deleted += (int) $rows;
        }
        return true;
    }
}
