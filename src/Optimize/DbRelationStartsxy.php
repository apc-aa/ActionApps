<?php

namespace AA\Optimize;

use function _m;
use function freeDB;
use function getDB;
use function GetTable2Array;
use function quote;

/** Testing if relation between slices are OK (without x or y chars on the begining)
 */
class DbRelationStartsxy extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Slices relation started by x or y records");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Testing if relation between slices are OK (without x or y chars on the begining)");
    }

    /** Test function
     * tests for x or y starts
     * @return bool
     */
    public function test(): bool {
        $SQL = 'SELECT count(*) as err_count FROM `content`,field WHERE (`text` LIKE \'x%\' OR `text` LIKE \'y%\') AND content.field_id = field.id AND LENGTH(`text`)=33 AND field.input_show_func LIKE \'%#sLiCe-%\'';
        $err_count = GetTable2Array($SQL, "aa_first", 'err_count');
        if ($err_count > 0) {
            $this->message(_m('%1 bad relation records found', [$err_count]));
            return false;
        }
        $this->message(_m('No bad relation records found'));
        return true;
    }

    /** Name function
     * @return bool
     */
    public function repair(): bool {
        $db = getDb();
        $SQL = 'SELECT `content`.item_id, `content`.`text` FROM `content`,field WHERE (`text` LIKE \'x%\' OR `text` LIKE \'y%\') AND content.field_id = field.id AND LENGTH(`text`)=33 AND field.input_show_func LIKE \'%#sLiCe-%\'';
        $err_text = GetTable2Array($SQL, "", 'aa_fields');
        if (is_array($err_text) and count($err_text) > 0) {
            $this->message(_m('%1 bad relation records found', [count($err_text)]));
            foreach ($err_text as $wrong) {
                $this->message(_m('repair value: %1 with item_id: %2',[ substr($wrong['text'], 1), $wrong['item_id'] ]));
                $SQL = "UPDATE `content` SET `text` = '" . quote(substr($wrong['text'], 1)) . "' WHERE item_id = '" . quote($wrong['item_id']) . "' AND `text` = '" . quote($wrong['text'], 1) . "'";
                $db->query($SQL);
            }
        }

        freeDb($db);
        return true;
    }
}