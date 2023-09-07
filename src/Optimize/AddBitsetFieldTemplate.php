<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddBitsetFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add bitset (flags, bitfield) field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'bitset'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'              => 'Bitset',
            'input_show_func'   => 'nul',
            'input_default'     => 'now',
            'alias1_func'       => 'f__:{math:_#this&1}',
            'html_default'      => '1',
            'input_validate'    => 'number',
            'input_insert_func' => 'com',
            'text_stored'       => '0'
        ];
    }
}