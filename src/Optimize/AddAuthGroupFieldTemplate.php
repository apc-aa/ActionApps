<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddAuthGroupFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add auth_group...... field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'auth_group'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'            => 'Auth Group',
            'input_pri'       => '350',
            'input_help'      => 'Sets permissions for web sections',
            'input_show_func' => 'sel:',
            'alias1'          => '_#AUTGROUP',
            'alias1_func'     => 'f_h:',
            'alias1_help'     => 'Auth Group (membership type)',
        ];
    }
}