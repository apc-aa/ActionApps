<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use function _m;

/** Recreates the new field types to be used as templates (in "ActionApps Core" slice) **/
class AddSeoFieldTemplate extends \AA\Optimize\AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Add seo fields template");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Updates field templates (in ActionApps Core slice) - redefine the field template");
    }

    /** the field identifier  */
    protected function fieldType(): string { return 'seo'; }

    /** field settings different from standard field setting  */
    protected function fieldSpecialSetting(): array {
        return [];
    }

    /** @return array - whole field definition - merged standard fields and special setting */
    protected function fieldDefinition(): array {
        $field_standard = [
            'id'                => $this->fieldType(),
            'type'              => '',
            'slice_id'          => '41415f436f72655f4669656c64732e2e',   // AA_Core_Fields..
            'name'              => mb_strtoupper(mb_substr($this->fieldType(), 0, 1)) . mb_substr($this->fieldType(), 1),
            'input_pri'         => '100',
            'input_help'        => '',
            'input_morehlp'     => '',
            'input_default'     => 'txt',
            'required'          => '0',
            'feed'              => '0',
            'multiple'          => '0',
            'input_show_func'   => 'fld',
            'content_id'        => '',
            'search_pri'        => '100',
            'search_type'       => '',
            'search_help'       => '',
            'search_before'     => '',
            'search_more_help'  => '',
            'search_show'       => '1',
            'search_ft_show'    => '1',
            'search_ft_default' => '1',
            'alias1'            => '',
            'alias1_func'       => '',
            'alias1_help'       => '',
            'alias2'            => '',
            'alias2_func'       => '',
            'alias2_help'       => '',
            'alias3'            => '',
            'alias3_func'       => '',
            'alias3_help'       => '',
            'input_before'      => '',
            'aditional'         => '',
            'content_edit'      => '',
            'html_default'      => '0',
            'html_show'         => '0',
            'in_item_tbl'       => '0',
            'input_validate'    => 'text',
            'input_insert_func' => 'qte',
            'input_show'        => '1',
            'text_stored'       => '1',
        ];
        return array_merge($field_standard, $this->fieldSpecialSetting());
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    function isType($type): bool { return in_array($type, ['sql_update']); }


    /** Test function
     * @return bool
     */
    public function test(): bool {
        $ret = true;
        if (!DB_AA::test('field', [['id', $this->fieldType()], ['slice_id', 'AA_Core_Fields..']])) {
            $this->message(_m('The "%1" field template missing', [$this->fieldType()]));
            $ret = false;
        } else {
            $this->message(_m('OK - The "%1" field template found', [$this->fieldType()]));
        }
        return $ret;
    }


    /** Main update function
     * @return bool
     */
    public function repair(): bool {
        $this->message(_m('Recreate "%1" field definition', [$this->fieldType()]));
        AA::Metabase()->doReplace('field', $this->fieldDefinition());
        $this->message(_m('New "%1" field template added', [$this->fieldType()]));
        return true;
    }
}