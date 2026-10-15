<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/** Testing if relation between slices are OK (without x or y chars on the begining)
 */
class DbRelationStartsxy extends AbstractOptimize
{
    /** shown on State Check - see AbstractOptimize::CHECK_* */
    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Slices relation started by x or y records");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Testing if relations between slices are OK (without x or y chars on the beginning)");
    }

    /** Test function
     * tests for x or y starts
     * @return bool
     */
    public function test(): bool
    {
        $SQL = 'SELECT count(*) as err_count FROM `content`,field WHERE (`text` LIKE \'x%\' OR `text` LIKE \'y%\') AND content.field_id = field.id AND LENGTH(`text`)=33 AND field.input_show_func LIKE \'%#sLiCe-%\'';
        $err_count = DB_AA::select1('err_count', $SQL);
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
    public function repair(): bool
    {
        $SQL = 'SELECT `content`.item_id, `content`.`text` FROM `content`,field WHERE (`text` LIKE \'x%\' OR `text` LIKE \'y%\') AND content.field_id = field.id AND LENGTH(`text`)=33 AND field.input_show_func LIKE \'%#sLiCe-%\'';
        $err_text = DB_AA::select([], $SQL);
        if ($err_text) {
            $this->message(_m('%1 bad relation records found', [count($err_text)]));
            foreach ($err_text as $wrong) {
                $this->message(_m('repair value: %1 with item_id: %2', [substr($wrong['text'], 1), $wrong['item_id'] ]));
                DB_AA::update('content', [['`text`', substr($wrong['text'], 1), 's']], [['item_id', $wrong['item_id'], 'b'], ['`text`', $wrong['text']]]);
            }
        }

        return true;
    }
}
