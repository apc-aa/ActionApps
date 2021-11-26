<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddPhoneFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add phone field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'phone'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [];
    }
}