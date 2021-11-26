<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddMailListsFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add mail_lists field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'mail_lists'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'            => 'Mailing Lists',
            'input_pri'       => '1000',
            'input_help'      => 'Select mailing lists which you read',
            'multiple'        => '1',
            'input_show_func' => 'mch::3:1',
            'alias1'          => '_#MAILLIST',
            'alias1_func'     => 'f_h:&nbsp;',
            'alias1_help'     => 'Mailing Lists',
        ];
    }
}