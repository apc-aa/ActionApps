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

    /** $where for `content`: an item id with a leading x or y in a relation field; null = no relation field.
     *  field.id repeats in every slice (aadb: 444 relation fields, 40 ids), so joining `field` read each
     *  content row once per slice and ran for minutes - the DB watchdog killed it. With the distinct ids
     *  it is one index range on the x/y prefix (76 ms there); `_` matches one character, so the pattern
     *  checks the length too.
     */
    private static function badRelationWhere(): ?array
    {
        $field_ids = DB_AA::select('', 'SELECT DISTINCT `id` FROM `field`', [['input_show_func', '#sLiCe-', 'CONTAIN']]);
        if (!$field_ids) {
            return null;
        }
        $any_id = str_repeat('_', 32);
        return [['field_id', $field_ids], [null, [[['`text`', "x$any_id", 'LIKE']], [['`text`', "y$any_id", 'LIKE']]], 'OR']];
    }

    /** Test function
     * tests for x or y starts
     * @return bool
     */
    public function test(): bool
    {
        $where = self::badRelationWhere();
        $err_count = $where ? DB_AA::count('content', $where) : 0;
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
        $where = self::badRelationWhere();
        $err_text = $where ? DB_AA::select([], 'SELECT `item_id`, `text` FROM `content`', $where) : [];
        if ($err_text) {
            $this->message(_m('%1 bad relation records found', [count($err_text)]));
            foreach ($err_text as $wrong) {
                // the same field id may be plain text in another slice - change only what is an item id
                if (!preg_match('/^[xy][0-9a-f]{32}$/', (string) $wrong['text'])) {
                    continue;
                }
                $this->message(_m('repair value: %1 with item_id: %2', [substr($wrong['text'], 1), $wrong['item_id'] ]));
                DB_AA::update('content', [['`text`', substr($wrong['text'], 1), 's']], [['item_id', $wrong['item_id'], 'b'], ['`text`', $wrong['text']]]);
            }
        }

        return true;
    }
}
