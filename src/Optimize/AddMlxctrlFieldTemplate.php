<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddMlxctrlFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add mlxctrl field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'mlxctrl'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'          => 'MLX Control',
            'input_pri'     => '6000',
            'input_morehlp' => 'https://actionapps.org/faq/detail.shtml?x=2290',
            'required'      => '1',
            'multiple'      => '1',
            'input_show'    => '0',
        ];
    }
}