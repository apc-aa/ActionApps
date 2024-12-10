<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:33
 */

namespace AA\Widget;

use AA_Content;
use AA_Property;

/** Two Boxes widget */
class Wi2Widget extends MchWidget {

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public static function name(): string
    {
        return _m('Two Boxes');   // widget name
    }

    /** returns if the widget handles multivalue or just single value   */
    public static function multiple(): bool {
        return true;
    }

    /** To use css for mch (for ajax...))
     * @inheritDoc
     */
    protected function getWidgetType(): string { return 'mch'; }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *
     * @param  AA_Property  $aa_property
     * @param  AA_Content   $content
     * @param  string       $type  normal|live|ajax
     *
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml(AA_Property $aa_property, AA_Content $content, string $type = 'normal'): array {
        $this->setProperty('columns', 1);
        return parent::_getRawHtml($aa_property, $content, $type);
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    public static function getClassProperties(): array {
        return Widget::propertiesShop(['const', 'row_count', 'offer_label', 'selected_label', 'slice_field', 'bin_filter', 'filter_conds', 'sort_by', 'add_form', 'additional_slice_pwd', 'const_arr']);
    }

    /** @inheritDoc */
    public static function description(): string {
        return ''; // TODO: Implement description() method.
    }
}