<?php

namespace AA\Optimize;

use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddHit30FieldTemplate extends \AA\Optimize\AddSeoFieldTemplate {

    public static function name(): string { return _m("Add hit_30 field template"); }

    /** the field identifier  */
    protected function fieldType(): string { return 'hit_30'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [
            'name'              => 'Hits last month',
            'input_default'     => 'qte',
            'required'          => '1',
            'html_default'      => '0',
            'input_validate'    => 'number',
            'input_insert_func' => 'num',
            'text_stored'       => '0',
        ];
    }
}