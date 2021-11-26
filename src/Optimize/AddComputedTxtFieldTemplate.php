<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddComputedTxtFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add computed_txt field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'computed_txt'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'            => 'Computed text',
            'input_show_func' => 'nul',
        ];
    }
}