<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:36
 */

namespace AA\Widget;

/** Hierachical constants widget */
class HcoWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('Hierachical constants');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['const', 'level_count', 'box_width', 'target_size', 'horizontal', 'first_selectable_level', 'level_names', 'const_arr']);
    }


    ///** Creates base widget HTML, which will be surrounded by Live, Ajxax
    // *  or normal decorations (added by _finalize*Html)
    // *  @param \AA_Property $aa_property
    // *  @param \AA_Content  $content
    // *  @param string       $type  normal|live|ajax
    // *  @return array
    // *  @throws \Exception
    // */
    //function _getRawHtml($aa_property, $content, $type='normal') {
    //    $base_name   = AA\FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
    //    $base_id     = AA\FormArray::formName2Id($base_name);
    //    $required    = $aa_property->isRequired() ? 'required' : '';
    //    $widget_add  = ($type == 'live') ? " class=\"live\" onkeypress=\"AA_StateChange('$base_id', 'dirty')\" onchange=\"AA_HcoDisplaySub(); AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';
    //    $widget_add2 = ($type == 'live') ? '<img width=16 height=16 border=0 title="'._m('To save changes click here or outside the field.').'" alt="'._m('Save').'" class="'.$base_id.'ico" src="'. AA_INSTAL_PATH.'images/px.gif" style="position:absolute; right:0; top:0;">' : '';
    //    $widget      = '';
    //
    //    $base_name_add = $base_name . '[hco]';
    //
    //
    //
    //    // property uses constants or widget have the array assigned (preselect is special - the constants here are not crucial)
    //    if ($this->getProperty('const') OR $this->getProperty('const_arr')) {  // todo - make preselect with real preselecting (maybe using AJAX)
    //        // This widget uses constants - show selectbox!
    //        $input_name   = $base_name_add. "[lev0][]";
    //
    //        $input_id     = AA\FormArray::formName2Id($input_name);
    //        $use_name     = $this->getProperty('use_name', false);
    //        $multiple     = $this->multiple() ? ' multiple' : '';
    //
    //        $widget    = "<select name=\"$input_name\" id=\"$input_id\"$multiple $required $widget_add>$widget_add2";
    //        $selected  = $content->getAaValue($aa_property->getId());
    //        $options   = $this->getOptions($selected, $content, $use_name, false, !$required);
    //        $widget   .= $this->getSelectOptions( $options );
    //        $widget   .= "</select><div id=\"sub$input_id\"></div>";
    //    }
    //
    //    return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
    //}
    //
    //function getOptions4Value($slice_id, $value) {
    //    $set     = new AA_Set(array($slice_id), new AA_Condition($this->getProperty('relation_field','relation........'), '=', $value), $this->getProperty('sort_by'));
    //    $options = GetFormatedItems( $set->query($zids), $this->getProperty('slice_field','_#HEADLINE'), $this->getProperty('additional_slice_pwd'));
    //    return "<select name=\"$input_name\" id=\"$input_id\"$multiple $required $widget_add>". $this->getSelectOptions( $options ). "</select>";
    //}
    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}