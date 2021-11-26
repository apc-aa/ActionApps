<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddDateFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add date field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'date'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'input_default'     => 'now',
            'input_show_func'   => 'dte::::2',
            'alias1_func'       => 'f_d:m/d/Y',
            'html_default'      => '0',
            'input_validate'    => 'date',
            'input_insert_func' => 'dte',
            'text_stored'       => '0',
        ];
    }
}