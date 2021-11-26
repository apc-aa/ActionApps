<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddIntegerFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add integer field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'integer'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'html_default'      => '0',
            'input_validate'    => 'number',
            'input_insert_func' => 'num',
            'text_stored'       => '0',
        ];
    }
}