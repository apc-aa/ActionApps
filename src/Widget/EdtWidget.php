<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:28
 */

namespace AA\Widget;

/** Rich Edit Text Area widget */
class EdtWidget extends TxtWidget
{
    /** name function  */
    public function name(): string
    {
        return _m('Rich Edit Text Area');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['rows', 'cols', 'area_type']);
    }

    function requireLibs($aa_property, $winfo)
    {
        return array_merge(['ckeditor'], parent::requireLibs($aa_property, $winfo));
    }

    /** script to run after the page DOM is loaded */
    function requireRun($aa_property, $winfo)
    {
        $ret = '';
        // are there some textareas not converted to CodeMirror?
        if ($this->ids_4_editor) {
            $ret .= "\n  document.querySelectorAll(\"". join(',',$this->ids_4_editor) ."\").forEach( function(el) { CKEDITOR.replace( el ).on('change', function(e) { e.editor.updateElement(); });} );";  // in order we can use AA_SendWidgetAjax() for sending bu ajax. Could be replaced by some custom event called from AA_SendWidgetAjax()
            // clean the list - all textareas are converted
            $this->ids_4_editor = [];
        }
        return $ret;
    }
}