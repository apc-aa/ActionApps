<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddFlagsFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add flags field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'flags'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'              => 'Flags',
            'input_show_func'   => 'nul',
            'input_default'     => 'now',
            'alias1_func'       => 'f__:{math#:_#this&1}',
            'html_default'      => '1',
            'input_validate'    => 'number',
            'input_insert_func' => 'com',
            'text_stored'       => '0'
        ];
    }
}