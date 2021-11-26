<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddCityFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add city field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'city'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [];
    }
}