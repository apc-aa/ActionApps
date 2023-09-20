<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA_Slice;
use function _m;

/** List Checkbox fields marked as required
 *
 */
class RequiredChecboxes extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("List Checkbox fields marked as required");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("AA are now more strict about Required field, so checkboxes marked as Required must be filled (=checked). This could be problem if you acidentaly have it set in history. This test just list all of such fields, in order you can check it.");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['test']; }

    /** Test function
     * @return bool
     */
    public function test(): bool {
        //$metabase = AA::Metabase();
        if ($fields = DB_AA::select([], 'SELECT `id`, LOWER(HEX(`slice_id`)) AS uslice_id, `name`, `input_show` FROM `field`', [
            [
                'required',
                1,
                'i',
            ],
            [
                'input_show_func',
                'chb:%',
                'LIKE',
            ],
        ])) {
            $this->message(_m('We found required checkbox %1 fields:', [count($fields)]));
            foreach ($fields as $field) {
                $this->message(_m('%1 - %2 (%3) Show: %4', [
                    $field['id'],
                    $field['name'],
                    AA_Slice::getModuleName($field['uslice_id']),
                    $field['input_show'],
                ]));
            }
        } else {
            $this->message(_m('OK - There is no required checkbox field'));
        }
        return true;
    }
}