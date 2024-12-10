<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:31
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Content;
use AA_Property;

/** Select Box widget */
class SelWidget extends Widget
{
    /** @var array  list of selects for select2 library */
    protected array $ids_4_select2 = [];


    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public static function name(): string
    {
        return _m('Select Box');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    public static function getClassProperties(): array {
        return Widget::propertiesShop(['const', 'slice_field', 'use_name', 'bin_filter', 'filter_conds', 'sort_by', 'additional_slice_pwd', 'const_arr', 'select_plus']);  // it also counts with 'filter_conds_rw', but it is not used for this widget
    }

    protected function requireLibs($aa_property, $winfo): array {
        return ['select2@4','aa-jslib@3'];
        //    return array_merge(['select2'], parent::requireLibs($aa_property, $winfo));
    }

    /** script to run after the page DOM is loaded */
    protected function requireRun($aa_property, $winfo): string {
        $ret = '';
        // are there some textareas not converted to CodeMirror?
        if ($this->ids_4_select2) {
            $sel2ids = implode(',',$this->ids_4_select2);
            $ret .= <<<JS
               const conf = {
                    matcher: (params, data) => {
                        if (!params.term) return data;
                        if (data.text && data.text.toLowerCase().includes(params.term.toLowerCase())) {
                            return data;
                        }
                        return null;
                    }
               }
               document.querySelectorAll('$sel2ids').forEach( function(el) { $(el).select2(conf).on('select2:open', () => { document.querySelector('.select2-search__field').focus();}); } );
            JS;
            $this->ids_4_select2 = [];              // clean the list - all textareas are converted
        }
        return $ret;
    }

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
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $required = $aa_property->isRequired() ? 'required' : '';

        $attrs = Widget::stdInputAttrs($type, $base_id, ['dirty']);
        $widget_add = htmlattr($attrs);

        //$widget_adds = ($type === 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';   //style=\"padding-left:16px;\"" : '';
        $widget = '';
        $autofocus = ($type === 'ajax') ? 'autofocus' : '';

        // This widget uses constants - show selectbox!
        $input_name = $base_name . "[]";
        $input_id = FormArray::formName2Id($input_name);
        $this->ids_4_select2[] = "#$input_id";
        $multiple = self::multiple() ? ' multiple' : '';

        //$widget    = AA\Widget\Widget::_saveIcon($base_id, $type === 'live', 'left')."<select name=\"$input_name\" id=\"$input_id\"$multiple $required $widget_adds $autofocus>";
        $widget = Widget::_saveIcon($base_id, $type === 'live') . "<select name=\"$input_name\" id=\"$input_id\"$multiple $required $widget_add $autofocus>";
        $selected = $content->getAaValue($aa_property->getId());
        // empty select option for not required fields and also for live selectbox,
        // because people thinks, that the first value is filled in the database (which is not)
        $add_empty = (!$required ? 1 : 2);
        $options = $this->getOptions($selected, $content, (bool)$this->getProperty('use_name', false), false, $add_empty);
        $widget .= $this->getSelectOptions($options);
        $widget .= "</select>";

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }


    /**
     * @inheritDoc
     */
    public static function description(): string {
        return ''; // TODO: Implement description() method.
    }
}