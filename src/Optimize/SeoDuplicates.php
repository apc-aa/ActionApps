<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA_Module;
use AA_Slice;
use function _m;

/**
 *
 */
class SeoDuplicates extends AbstractOptimize {

    /** Name function
     * @return string message
     */
    public static function name(): string {
        return _m("SEO name duplicates");
    }

    /** Description function
     * @return string message
     */
    public static function description(): string {
        return _m("Check items for duplicate SEO name inside sitemodule");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['test']; }

    /** Test function
     * @return bool
     */
    public function test(): bool {
        AA_Module::getUserModules();
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