<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddSecretFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add secret field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'secret'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'input_help' => 'Secret key for 2FA - encrypted, not accessible as normal field',
            'input_pri'  => '2056',
        ];
    }
}