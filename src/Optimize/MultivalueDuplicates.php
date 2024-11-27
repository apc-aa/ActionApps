<?php

namespace AA\Optimize;

use function _m;
use function getDB;
use function GetTable2Array;
use function quote;

/**
 */
class MultivalueDuplicates extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Multivalue Duplicates");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Removes duplicate values in multivalue text fields");
    }

    /** Test function
     * tests for duplicate entries
     * @return bool
     */
    public function test(): bool {
        $ret = true;

        $SQL = "SELECT `item_id`, `field_id`, `text`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag & 64) GROUP BY `item_id`, `field_id`, `text`, `number` HAVING `cnt` >1";
        $err_text = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_text) and count($err_text)) {
            $this->message(_m('%1 duplicates found in text fields', [count($err_text)]));
            foreach ($err_text as $wrong) {
                $this->message(_m('Duplicates (%4) in item %1 - field %2 - value %3', [
                    unpack_id($wrong['item_id']),
                    $wrong['field_id'],
                    $wrong['text'],
                    $wrong['cnt'],
                ]));
            }
            $ret = false;
        }

        $SQL = "SELECT `item_id`, `field_id`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag & 64) = 0 GROUP BY `item_id`, `field_id`, `number` HAVING `cnt` >1";
        $err_num = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_num) and count($err_num)) {
            $this->message(_m('%1 duplicates found in numeric fields', [count($err_num)]));
            foreach ($err_num as $wrong) {
                $this->message(_m('Duplicates (%4) in item %1 - field %2 - value %3 - numeric', [
                    unpack_id($wrong['item_id']),
                    $wrong['field_id'],
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
    public function repair(): bool {
        $db = getDb();

        $ret = true;

        $SQL = "SELECT `item_id`, `field_id`, `text`, count(*) AS `cnt` FROM `content` WHERE (flag & 64) GROUP BY `item_id`, `field_id`, `text` HAVING `cnt` >1";
        $err_text = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_text) and count($err_text)) {
            $this->message(_m('%1 duplicates found in text fields', [count($err_text)]));
            foreach ($err_text as $wrong) {
                $SQL = "DELETE FROM `content` WHERE item_id='" . quote($wrong['item_id']) . "' AND field_id='" . quote($wrong['field_id']) . "' AND text='" . quote($wrong['text']) . "' LIMIT " . ($wrong['cnt'] - 1);
                $db->query($SQL);
            }
            $ret = false;
        }

        $SQL = "SELECT `item_id`, `field_id`, `number`, count(*) AS `cnt` FROM `content` WHERE (flag & 64) = 0 GROUP BY `item_id`, `field_id`, `number` HAVING `cnt` >1";
        $err_num = GetTable2Array($SQL, '', 'aa_fields');

        if (is_array($err_num) and count($err_num)) {
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