<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddIpFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add ip field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'ip'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return ['name' => 'IP address'];
    }
}