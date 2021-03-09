<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:33
 */

namespace AA\Widget;
use AA;
use AA\FormArray;
use AA_Property;
use AA_Validate;
use AA_Value;

/** File Upload widget */
class FilWidget extends Widget
{

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    public function name(): string
    {
        return _m('File Upload');   // widget name
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop(['allowed_ftypes', 'label', 'hint', 'display_url', 'multiple']);
    }

//    function requireLibs($aa_property, $winfo)
//    {
//        return array('https://unpkg.com/filepond/dist/filepond.css', 'https://unpkg.com/filepond/dist/filepond.js');
//    }
//
//    /** script to run after the page DOM is loaded */
//    function requireRun($aa_property, $winfo)
//    {
//        return '
//
//        FilePond.setOptions({
//            allowReplace: false,
//            instantUpload: false,
//            server: {
//                url: \'http://192.168.33.10\',
//                process: \'./process.php\',
//                revert: \'./revert.php\',
//                restore: \'./restore.php?id=\',
//                fetch: \'./fetch.php?data=\'
//            }
//        });
//
//        FilePond.parse(document.body);
//      ';
//    }


    /** @return AA_Value for the data send by the widget
     *   This is compound widgets, which consists from more than one input - filled
     *   URL of the file or name of input[type=file] for upload,
     *   so the inputs looks like:
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][img_upload______][fil][var][]  // array of uploaded files info - ['_0'=>['name'=>..,'type'=>..,'tmp_name'=>..,'error'=>..,'size'=>..],..]
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][img_upload______][fil][url][]  // url
     *
     *   This method AA\Widget\FilWidget::getValue() is called to grab the value
     *   (or multivalues) from the submitted form. The function actually do not
     *   upload the file. The upload itself is done by insert_fnc_fil() later
     *   Here we just mark the uploaded file by prefix AA_UPLOAD:, so
     *   insert_fnc_fil() knows about the new file for upload
     *
     * @param $data4field - array('var'=>array(), 'url'=>array())
     */
    public static function getValue($data4field): AA_Value
    {
        $uploads = (array)$data4field['var'];
        $urls = (array)$data4field['url'];
        $add = $data4field['add']=='1';  // if the widget is multiple, then it behaves differntly with deletion - the old uploads are not cleaned

        // upload could be also multivalue
        $max = max(count($uploads), count($urls));

        $values = [];

        $upload_ok = false;
        if (!empty($uploads)) {
            foreach ($uploads as $file) {
                if (isset($file['error']) AND ($file['error']==0)) { // 0 means UPLOAD_ERR_OK - sometimes (when using FormData JS API) the array is present even for empty field upload
                    $upload_ok = true;
                    // the information about file is in array - ['name'=>..,'type'=>..,'tmp_name'=>..,'error'=>..,'size'=>..]
                    $values[] = 'AA_UPLOAD:' . ParamImplode(array_values($file));
                }
            }
        }
        if (!$upload_ok OR $add) {
            foreach ($urls as $url) {
                $values[] = $url;
            }
        }
        return new AA_Value($values);
    }


    /** Creates base widget HTML, which will be surrounded by Live, Ajxax
     *  or normal decorations (added by _finalize*Html)
     *  @param AA_Property $aa_property
     *  @param \AA_Content  $content
     *  @param string      $type  normal|live|ajax
     *  @return array
     *  @throws \Exception
     */
    function _getRawHtml($aa_property, $content, $type = 'normal')
    {
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);
        $widget_add = ($type == 'live') ? " class=\"live\" onchange=\"AA_SendWidgetLive('$base_id', this, AA_LIVE_OK_FUNC)\"" : '';

        $widget = '';
        $delim = '';
        $width = $this->getProperty('width', 60);            // @todo - width is not property of file widget, yet
        $max_characters = (int)$this->getProperty('max_characters', 254);  // @todo - width is not property of file widget, yet
        $display_url = (int)$this->getProperty('display_url', 0);
        $multiple = (int)$this->getProperty('multiple', 0) ? ' multiple' : '';
        $value = $content->getAaValue($aa_property->getId());

        for ($i = 0, $ino = $value->count(); $i < $ino; ++$i) {
            $input_name = $base_name . "[fil][url][$i]";
            $input_id = FormArray::formName2Id($input_name);
            $input_value = myspecialchars($value->getValue($i));
            $link = $input_value ? a_href($input_value, GetAAImage('external-link.png', _m('Show'), 16, 16)) : '';
            if (($display_url < 2) AND ($type == 'normal')) {
                $widget .= $delim . "\n<input type=\"text\" size=\"$width\" maxlength=\"$max_characters\" name=\"$input_name\" id=\"$input_id\" value=\"$input_value\"$widget_add>&nbsp;$link";
            } else {
                $widget .= "\n<input type=\"hidden\" name=\"$input_name\" id=\"$input_id\" value=\"$input_value\">$input_value";
            }
            $delim = '<br>';
        }

        // if the widget is multiple, then it behaves differntly with deletion - the old uploads are not cleaned unles you say so
        if ($widget AND $multiple) {
            $input_name = $base_name . "[fil][add]";
            $input_id = FormArray::formName2Id($input_name);
            $widget .= "\n<input type=\"hidden\" name=\"$input_name\" id=\"$input_id\" value=\"1\">";
        }

//        if ($_GET['dd']=='wer') {
//            huhl($display_url, $widget, $value, $value->count(), $content, $this);
//        }
        // no input was printed, we need to print one
        if (!$widget AND ($display_url == 0)) {
            $input_name = $base_name . "[fil][url][0]";
            $input_id = FormArray::formName2Id($input_name);
            $widget = "\n<input type=\"text\" size=\"$width\" maxlength=\"$max_characters\" name=\"$input_name\" id=\"$input_id\" value=\"\"$widget_add>";
        }

        $input_name = $base_name . "[fil][var][]";
        $input_id = FormArray::formName2Id($input_name);
        //$input_name = "testfileupload";
        if ($type == 'normal') {
            $required = ($aa_property->isRequired() AND !$value->count() AND ($display_url > 0)) ? 'required' : '';
            $widget .= "    <br><input type=file class=filepond size=\"$width\"$multiple maxlength=\"$max_characters\" name=\"$input_name\" id=\"$input_id\" $required><!--$type -->";
        } else {
            $url_params = [
                'inline' => 1,
                'ret_code_js' => 'parent.AA_ReloadAjaxResponse(\'' . $base_id . '\', AA_ITEM_JSON)'
            ];
            $widget .= '
                <form id="fuf' . $base_id . '" method="POST" enctype="multipart/form-data" action="' . myspecialchars(get_aa_url('filler.php3', $url_params)) . '" target="iframe' . $base_id . '">';
            if ($link) {
                $widget .= '
                    <input type="button" value="' . _m('Delete') . '" onclick="document.getElementById(\'fuf' . $base_id . '\').submit()"><br>';
            }
            $widget .= '
                <input type="file" size="' . $width . '"'. $multiple.' maxlength="' . $max_characters . '" name="' . $input_name . '" id="' . $input_id . '" onchange="document.getElementById(\'' . $base_id . 'upload\').style.display = ((this.value == \'\') ? \'none\' : \'inline-block\');">
                <input type="hidden" name="ret_code_enc" id="ret_code_enc' . $base_id . '" value="">
                <input type="submit" name="' . $base_id . 'upload" id="' . $base_id . 'upload" value="' . _m('Upload') . '" style="display:none;">
                </form>
                <iframe id="iframe' . $base_id . '" name="iframe' . $base_id . '" src="" style="width:0;height:0;border:0px solid #fff;visibility:hidden;"></iframe>
                <script>
                  document.getElementById("ret_code_enc' . $base_id . '").value = document.getElementById("ajaxv_' . $base_id . '").getAttribute(\'data-aa-alias\');
                </script>
            ';
        }
        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }

    /** Get Live HTML widget (in place editing)
     * @param AA_Property $aa_property  - the variable
     * @param \AA_Content  $content      - contain the value of property to display
     * @param null $function
     * @return string - widget HTML for using as Live component (in place editing)
     * @throws \Exception
     */
    function getLiveHtml($aa_property, $content, $function = null)
    {
        //return $this->getAjaxHtml($aa_property, $content);
        // this is not standard implementation - we reuse Ajax function instead of Live function, because it is more natural for file upload
        $url = $content->getValue($aa_property->getId());

        if ($url AND AA_Validate::doValidate($url, 'url')) {
            $link = '&nbsp;' . a_href($url, GetAAImage('external-link.png', _m('Show'), 16, 16));
        };

        return AA::Stringexpander()->unalias('{ajax:' . $content->getId() . ':' . $aa_property->getId() . ':{({item:' . $content->getId() . ':' . $aa_property->getId() . '})}' . $link . '<br><input type=button value=' . _m('Upload') . '>}');
        // add JS OK Function
        //return str_replace('AA_LIVE_OK_FUNC', $function ? $function : "''", $this->_finalizeAjaxHtml($this->_getRawHtml($aa_property, $content)));
    }

    function _finalizeAjaxHtml($winfo, $aa_property)
    {
        // not standard - we do not show save button (the upload input works the same way here)
        $base_name = $winfo['base_name'];
        $base_id = FormArray::formName2Id($base_name);
        $help = $aa_property->getHelp();
        $widget_html = '<div class=ajax_widget>';
        $widget_html .= "\n" . $winfo['html'] . ($help ? "\n    <div class=\"aa-help\"><small>$help</small></div>\n" : '');
        $widget_html .= "\n<div class=ajax_buttons>";
        $widget_html .= "\n<input class=\"cancel-button\" type=\"button\" value=\"" . _m('EXIT WITHOUT CHANGE') . "\" onclick=\"(arguments[0] || window.event).stopPropagation();DisplayInputBack('$base_id');\">";
        $widget_html .= "\n</div>";
        $widget_html .= "\n</div>";
        return $widget_html;
    }

    /** @inheritDoc */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}