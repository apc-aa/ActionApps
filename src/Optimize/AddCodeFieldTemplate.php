<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddCodeFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add code field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'code'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['input_show_func' => 'cod'];
    }
}