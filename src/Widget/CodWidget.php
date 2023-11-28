<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:35
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Value;

/** Code editor */
class CodWidget extends TxtWidget
{
    /** widget name  */
    public static function name(): string {
        return _m('Code editor');
    }

    protected function requireLibs($aa_property, $winfo): array {
        return array_merge(['codemirror@5'], parent::requireLibs($aa_property, $winfo));
    }

    /** script to run after the page DOM is loaded */
    protected function requireRun($aa_property, $winfo): string {
        $ret = '';
        // are there some textareas not converted to CodeMirror?
        if ($this->ids_4_editor) {
            $ret .= "\n  document.querySelectorAll(\"". join(',',$this->ids_4_editor) .'").forEach( function(el) { CodeMirror.fromTextArea(el, { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed", spellcheck:true, inputStyle: "contenteditable" }).on("change", function(editor) {editor.save(); const ta = editor.getTextArea(); if (typeof ta.onchange === "function") { ta.onchange() }; }) });';
            // clean the list - all textareas are converted
            $this->ids_4_editor = [];
        }
        return $ret;
    }

    /** @return AA_Value for the data send by the widget
     */
    public static function getWidgetValue(array $data4field): AA_Value
    {
        return parent::getWidgetValue($data4field)->replaceInValues(["\xc2\xa0"], [' ']);
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['rows']);
    }

    //    /** Creates base widget HTML, which will be surrounded by Live, Ajax
    //     *  or normal decorations (added by _finalize*Html)
    //     *  @param \AA_Property $aa_property
    //     *  @param \AA_Content  $content
    //     *  @param string       $type  normal|live|ajax
    //     *  @return array
    //     *  @throws \Exception
    //     */
    //    function _getRawHtml($aa_property, $content, $type = 'normal'): array
    //    {
    //        $property_id = $aa_property->getId();
    //        $base_name = FormArray::getName4Form($property_id, $content, $this->item_index);
    //        $base_id = FormArray::formName2Id($base_name);
    //        $input_name = $base_name . "[0]";
    //        $input_flag = $base_name . "[flag]";
    //        $value = $content->getAaValue($aa_property->getId());
    //        $input_value = safe($value->getValue(0));
    //        $rows = $this->getProperty('rows', min(substr_count($value[0], "\n") + 4, 60));
    //
    //
    //        //$ret = "<textarea id=\"$input_id\" name=\"$input_name\" rows=\"$rows\"$maxlength $required $widget_add>$input_value</textarea>$widget_add2";
    //        $widget = "<textarea id=\"$base_id\" name=\"$input_name\" rows=\"$rows\" data-editor=html>$input_value</textarea>";
    //        $this->ids_4_editor[] = '#'.$base_id;
    //
    //        $widget .= "<input type=hidden name=\"$input_flag\" value=\"" . FLAG_HTML . "\">";
    //
    //        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    //    }
}

// AA\Widget\Widget class should implement some interface (in php5), so it is possible
// to use AA_Components factory, ... methods
// used for easy usage of factory, adding new user widgets, and selectbox
// AA\Widget\Widget should became abstract in php5


//class AA\Widget\CodWidget extends AA\Widget\Widget {

//    /** widget name  */
//    public static function name(): string {
//        return _m('Code editor');
//    }

//    function requireLibs($aa_property, $winfo) {
//        return array('ace@1');
//    }

//    /** script to run after the page DOM is loaded */
//    function requireRun($aa_property, $winfo)  {
//      return "
//      $('textarea[data-editor]').each(function() {
//        var textarea = $(this);
//        var mode     = textarea.data('editor');
//        var editDiv = $('<div>', {
//          position: 'absolute',
//          width: '100%',   //textarea.width(),
//          height: textarea.height(),
//          'class': textarea.attr('class')
//        }).insertBefore(textarea);
//        textarea.css('display', 'none');
//        var editor = ace.edit(editDiv[0]);
//        editor.renderer.setShowGutter(false);
//        // editor.renderer.setShowGutter(textarea.data('gutter'));
//        editor.getSession().setValue(textarea.val());
//        editor.getSession().setTabSize(2);
//        editor.getSession().setMode('ace/mode/' + mode);
//        editor.setTheme('ace/theme/chrome');

//        // copy back to textarea on form submit...
//        textarea.closest('form').submit(function() {
//          textarea.val(editor.getSession().getValue());
//        })
//      });";
//    }


//    /** getClassProperties function of AA_Serializable
//     *  Used parameter format (in fields.input_show_func table)
//     */
//     static function getClassProperties()  {
//        return AA\Widget\Widget::propertiesShop(array('rows'));
//    }

//    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
//     *  or normal decorations (added by _finalize*Html)
//     *  @param \AA_Property $aa_property
//     *  @param \AA_Content  $content
//     *  @param string       $type  normal|live|ajax
//     *  @return array
//     *  @throws \Exception
//     */
//    function _getRawHtml($aa_property, $content, $type='normal'): array {
//        $property_id  = $aa_property->getId();
//        $base_name    = AA\FormArray::getName4Form($property_id, $content, $this->item_index);
//        $base_id      = AA\FormArray::formName2Id($base_name);
//        $input_name   = $base_name."[0]";
//        $input_flag   = $base_name."[flag]";
//        $value        = $content->getAaValue($aa_property->getId());
//        $input_value  = safe($value->getValue(0));
//        $rows         = $this->getProperty('rows', min(substr_count($value[0],"\n")+4, 60));


//        //$ret = "<textarea id=\"$input_id\" name=\"$input_name\" rows=\"$rows\"$maxlength $required $widget_add>$input_value</textarea>$widget_add2";
//        $widget       = "<textarea id=\"$base_id\" name=\"$input_name\" rows=\"$rows\" data-editor=html data-gutter=false>$input_value</textarea>";
//        $widget      .= "<input type=hidden name=\"$input_flag\" value=\"".FLAG_HTML."\">";

//        return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
//    }
//}
