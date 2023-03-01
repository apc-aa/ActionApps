<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddUrlPreviewFieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add _url_preview field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return '_url_preview'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'       => 'Preview Url',
            'input_help' => 'Url for item preview - could contain AA Expressions',
        ];
    }
}