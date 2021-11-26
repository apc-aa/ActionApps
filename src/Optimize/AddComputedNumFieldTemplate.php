<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddComputedNumFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add computed_num field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'computed_num'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'              => 'Computed number',
            'input_show_func'   => 'nul',
            'html_default'      => '0',
            'input_validate'    => 'number',
            'input_insert_func' => 'com',
            'text_stored'       => '0',
        ];
    }
}