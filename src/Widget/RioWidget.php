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
    public function name(): string
    {
        return _m('Radio Button');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['const', 'columns', 'move_right', 'slice_field', 'bin_filter', 'filter_conds', 'sort_by', 'additional_slice_pwd', 'const_arr']);
    }

    /** Returns one checkbox tag - Used in removed inputMultiChBox */
    function getRadioButtonTag($option, $input_name, $input_id, $add = '')
    {
        $missing = $option['mis'] ? ' class=aa-missing-option' : '';
        $ret = "\n<label$missing><input type=radio name=\"$input_name\" id=\"$input_id\" value='" . myspecialchars($option['k']) . "' $add";
        if ($option['selected']) {
            $ret .= " checked";
        }
        $ret .= ">" . myspecialchars($option['v']) . '</label>';
        return $ret;
    }

    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *  @param \AA_Property $aa_property
     *  @param \AA_Content  $content
     *  @param string       $type  normal|live|ajax
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);

        $required = $aa_property->isRequired() ? 'required' : '';
        $widget_add = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';
        $widget_add2 = ($type == 'live') ? '<img width=16 height=16 border=0 title="' . _m('To save changes click here or outside the field.') . '" alt="' . _m('Save') . '" class="' . $base_id . 'ico" src="' . AA_INSTAL_PATH . 'images/px.gif" style="position:absolute; right:0; top:0;">' : '';

        $use_name = $this->getProperty('use_name', false);

        $input_name = $base_name . "[]";
        $input_id = FormArray::formName2Id($input_name);
        $selected = $content->getAaValue($aa_property->getId());
        $options = $this->getOptions($selected, $content, $use_name);
        $htmlopt = [];
        for ($i = 0, $ino = count($options); $i < $ino; ++$i) {
            $htmlopt[] = $this->getRadioButtonTag($options[$i], $input_name, $input_id . $i, "$widget_add $required");
            // $required     = ''; // for radio it could be in all options
        }

        $widget = $this->getInMatrix($htmlopt, $this->getProperty('columns', 0), $this->getProperty('move_right', false), 'aa-tab-rio') . $widget_add2;
        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}