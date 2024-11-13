<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:31
 */

namespace AA\Widget;
use AA\FormArray;

/** Radio Button widget */
class RioWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public static function name(): string
    {
        return _m('Radio Button');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    public static function getClassProperties(): array {
        return Widget::propertiesShop(['const', 'columns', 'move_right', 'slice_field', 'bin_filter', 'filter_conds', 'sort_by', 'additional_slice_pwd', 'const_arr', 'other_option']);
    }

    /** script to run after the page DOM is loaded */
    protected function requireRun($aa_property, $winfo): string {
        $ret = '';
        if ($this->getProperty('other_option', false)) {
            $ret .= <<<JS
               document.querySelectorAll('.aa-tab-rio-other input[type="radio"]').forEach((rb) => { rb.addEventListener('change', (event) => {  // must be set on every radio button - change is not fired on uncheck 
                   const last = document.querySelector('.aa-rio-other-option input[type="radio"][name="'+event.target.name+'"]');
                   const otherText = document.getElementById(last.id + 'txt');
                   if (otherText) {
                      otherText.style.display = last.checked ? '' : 'none'; // document.querySelector('input[type="radio"][name="'+event.target.name+'"]'  ).checked ? '' : 'none';
                   }
               })});
               JS;
        }
        return $ret;
    }

    /** Returns one checkbox tag - Used in removed inputMultiChBox */
    function getRadioButtonTag($option, $input_name, $input_id, $add = '', $other = false): string
    {
        $class = $other ? ' class=aa-rio-other-option' : ($option['mis'] ? ' class=aa-missing-option' : '');
        $input_text = '';
        if ($other) {
            $input_text = "<span class=aa-rio-other-txt><input id=\"{$input_id}txt\" onchange=\"document.getElementById('{$input_id}').value=this.value\" placeholder=\"".safe($option['k']).'" value="'. ( $option['mis'] ? safe($option['k']) : '').'"';
            if (!$option['mis']) {
                $input_text .= ' style="display:none;">';
            }
        }
        $ret = "\n<label$class><input type=radio name=\"$input_name\" id=\"$input_id\" value='" . safe($option['k']) . "' $add";
        if ($option['selected'] OR ($other AND $option['mis'])) {
            $ret .= " checked";
        }
        $ret .= "><span>" . safe($option['v']) . "    </span>$input_text</label>";
        return $ret;
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *  @param \AA_Property $aa_property
     *  @param \AA_Content  $content
     *  @param string       $type normal|live|ajax
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml($aa_property, $content, $type = 'normal'): array {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);

        $required = $aa_property->isRequired() ? 'required' : '';

        $attrs = Widget::stdInputAttrs($type, $base_id, ['dirty']);
        $widget_add = htmlattr($attrs);
        $widget_add2 = ($type === 'live') ? '<img width=16 height=16 title="' . _m('To save changes click here or outside the field.') . '" alt="' . _m('Save') . '" class="' . $base_id . 'ico" src="' . AA_INSTAL_PATH . 'images/px.gif" style="position:absolute; right:0; top:0;">' : '';

        $use_name = $this->getProperty('use_name', false);
        $other    = $this->getProperty('other_option', false);

        $input_name = $base_name . "[]";
        $input_id = FormArray::formName2Id($input_name);
        $selected = $content->getAaValue($aa_property->getId());
        $options = $this->getOptions($selected, $content, $use_name);
        $htmlopt = [];
        for ($i = 0, $ino = count($options); $i < $ino; ++$i) {
            if ($other && ($i === $ino-2) && $options[$ino-1]['mis']) {  // special case - the filled Other option - use mis value added as last option and name from current option
                $opt = $options[$ino-1];
                $opt['v'] = $options[$i]['v'];
                $htmlopt[] = $this->getRadioButtonTag($opt, $input_name, $input_id . $i, "$widget_add $required", true);
                break;
            }

            $htmlopt[] = $this->getRadioButtonTag($options[$i], $input_name, $input_id.$i, "$widget_add $required", ($other and $i == $ino - 1));
            // $required     = ''; // for radio it could be in all options
        }

        $widget = $this->getInMatrix($htmlopt, $this->getProperty('columns', 0), $this->getProperty('move_right', false), $other ? 'aa-tab-rio aa-tab-rio-other' : 'aa-tab-rio') . $widget_add2;
        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /**
     * @inheritDoc
     */
    public static function description(): string {
        return ''; // TODO: Implement description() method.
    }
}
