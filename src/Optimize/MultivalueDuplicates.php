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
        $ret = true;

        $err_text = DB_AA::select([], "SELECT `item_id`, `field_id`, `text`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag & " . FLAG_TEXT_STORED . ") AND " . self::IN_MULTIROW_FIELD . " GROUP BY `item_id`, `field_id`, `text`, `number` HAVING `cnt` >1");

        if ($err_text) {
            $this->message(_m('%1 duplicates found in text fields', [count($err_text)]));
            foreach ($err_text as $wrong) {
                $this->message(_m('Duplicates (%4) in item %1 - field %2 - value %3', [
                    unpack_id($wrong['item_id']),
                    safe($wrong['field_id']),
                    safe($wrong['text']),   // editor input, the report is HTML
                    $wrong['cnt'],
                ]));
            }
            $ret = false;
        }

        // no text column here - the plain GROUP BY is cheaper than the pair lookup
        $err_num = DB_AA::select([], "SELECT `item_id`, `field_id`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag & " . FLAG_TEXT_STORED . ") = 0 GROUP BY `item_id`, `field_id`, `number` HAVING `cnt` >1");

        if ($err_num) {
            $this->message(_m('%1 duplicates found in numeric fields', [count($err_num)]));
            foreach ($err_num as $wrong) {
                $this->message(_m('Duplicates (%4) in item %1 - field %2 - value %3 - numeric', [
                    unpack_id($wrong['item_id']),
                    safe($wrong['field_id']),
                    $wrong['number'],
                    $wrong['cnt'],
                ]));
            }
            $ret = false;
        }

        if ($ret) {
            $this->message(_m('No duplicates found, hurray!'));
        }
        return $ret;
    }

    /** Name function
     * @return bool
     */
    public function repair(): bool
    {
        $db = getDb();

        $ret = true;

        $err_text = DB_AA::select([], "SELECT `item_id`, `field_id`, `text`, count(*) AS `cnt` FROM `content` WHERE (flag & " . FLAG_TEXT_STORED . ") AND " . self::IN_MULTIROW_FIELD . " GROUP BY `item_id`, `field_id`, `text` HAVING `cnt` >1");

        if ($err_text) {
            $this->message(_m('%1 duplicates found in text fields', [count($err_text)]));
            foreach ($err_text as $wrong) {
                $SQL = "DELETE FROM `content` WHERE item_id='" . quote($wrong['item_id']) . "' AND field_id='" . quote($wrong['field_id']) . "' AND text='" . quote($wrong['text']) . "' LIMIT " . ($wrong['cnt'] - 1);
                $db->query($SQL);
            }
            $ret = false;
        }

        $err_num = DB_AA::select([], "SELECT `item_id`, `field_id`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag & " . FLAG_TEXT_STORED . ") = 0 GROUP BY `item_id`, `field_id`, `number` HAVING `cnt` >1");

        if ($err_num) {
            $this->message(_m('%1 duplicates found in numeric fields', [count($err_num)]));
            foreach ($err_num as $wrong) {
                $SQL = "DELETE FROM `content` WHERE item_id='" . quote($wrong['item_id']) . "' AND field_id='" . quote($wrong['field_id']) . "' AND number='" . quote($wrong['number']) . "' LIMIT " . ($wrong['cnt'] - 1);
                $db->query($SQL);
            }
            $ret = false;
        }

        if ($ret) {
            $this->message(_m('No duplicates found, hurray!'));
        }
        return $ret;
    }
}
