<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use function _m;

/** Fix older format of date widget parameters
 *
 */
class FixDatewidget extends AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Fix older format of date widget parameters");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("AA used to use ' as parameter delimiter in some early stages instead of : - like date widget 1'10'1. This fixes it to current 1:10:1. Functionaly it is equal.");
    }

    /** Test function
     * @return bool
     */
    public function test(): bool {
        //$metabase = AA::Metabase();
        if ($fields = DB_AA::select([], 'SELECT `id`, LOWER(HEX(`slice_id`)) AS uslice_id, `name`, `input_show_func` FROM `field`', [
            [
                'input_show_func',
                "dte:%'%",
                'LIKE',
            ],
        ])) {
            $this->message(_m('We found %1 old delimiters:', [count($fields)]));
            foreach ($fields as $field) {
                $this->message(_m('%1 - %2 (%3) Params: %4', [
                    $field['id'],
                    $field['name'],
                    AA_Slice::getModuleName($field['uslice_id']),
                    $field['input_show_func'],
                ]));
            }
        } else {
            $this->message(_m('OK - There is no old delimiters'));
        }
        return true;
    }

    /** Repair function
     * @return bool
     */
    public function repair(): bool {
        //$metabase = AA::Metabase();
        $changed = DB_AA::sql("UPDATE field SET input_show_func=REPLACE(input_show_func, \"'\", ':')", [
            [
                'input_show_func',
                "dte:%'%",
                'LIKE',
            ],
        ]);
        $this->message(_m('Fixed %1 fields', [$changed]));
        return true;
    }

}