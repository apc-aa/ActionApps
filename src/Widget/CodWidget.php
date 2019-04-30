<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:35
 */

namespace AA\Widget;
use AA\FormArray;

/** Code editor */
class CodWidget extends \AA\Widget\Widget
{

    /** widget name  */
    function name()
    {
        return _m('Code editor');
    }

    function requireLibs($aa_property, $winfo)
    {
        return array('codemirror@5');
    }

    /** script to run after the page DOM is loaded */
    function requireRun($aa_property, $winfo)
    {
        return '
      var tas = document.querySelectorAll("textarea[data-editor]");
      for(var i = 0; i < tas.length; i++) {
        CodeMirror.fromTextArea(tas[i], { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" });
      }
      ';
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     */
    static function getClassProperties()
    {
        return \AA\Widget\Widget::propertiesShop(array('rows'));
    }

    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        $property_id = $aa_property->getId();
        $base_name = FormArray::getName4Form($property_id, $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $input_name = $base_name . "[0]";
        $input_flag = $base_name . "[flag]";
        $value = $content->getAaValue($aa_property->getId());
        $input_value = myspecialchars($value->getValue(0));
        $rows = $this->getProperty('rows', min(substr_count($value[0], "\n") + 4, 60));


        //$ret = "<textarea id=\"$input_id\" name=\"$input_name\" rows=\"$rows\"$maxlength $required $widget_add>$input_value</textarea>$widget_add2";
        $widget = "<textarea id=\"$base_id\" name=\"$input_name\" rows=\"$rows\" data-editor=html>$input_value</textarea>";
        $widget .= "<input type=hidden name=\"$input_flag\" value=\"" . FLAG_HTML . "\">";

        return array('html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired());
    }
}

// AA\Widget\Widget class should implement some interface (in php5), so it is possible
// to use AA_Components factory, ... methods
// used for easy ussage of factory, adding new user widgets, and selectbox
// AA\Widget\Widget should became abstract in php5


//class AA\Widget\CodWidget extends AA\Widget\Widget {

//    /** widget name  */
//    function name() {
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

//    function _getRawHtml($aa_property, $content, $type='normal') {
//        $property_id  = $aa_property->getId();
//        $base_name    = AA\FormArray::getName4Form($property_id, $content, $this->item_index);
//        $base_id      = AA\FormArray::formName2Id($base_name);
//        $input_name   = $base_name."[0]";
//        $input_flag   = $base_name."[flag]";
//        $value        = $content->getAaValue($aa_property->getId());
//        $input_value  = myspecialchars($value->getValue(0));
//        $rows         = $this->getProperty('rows', min(substr_count($value[0],"\n")+4, 60));


//        //$ret = "<textarea id=\"$input_id\" name=\"$input_name\" rows=\"$rows\"$maxlength $required $widget_add>$input_value</textarea>$widget_add2";
//        $widget       = "<textarea id=\"$base_id\" name=\"$input_name\" rows=\"$rows\" data-editor=html data-gutter=false>$input_value</textarea>";
//        $widget      .= "<input type=hidden name=\"$input_flag\" value=\"".FLAG_HTML."\">";

//        return array('html'=>$widget, 'last_input_name'=>$input_name, 'base_name' => $base_name, 'base_id'=>$base_id, 'required'=>$aa_property->isRequired());
//    }
//}
