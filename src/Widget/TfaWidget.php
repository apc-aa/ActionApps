<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 15.10.18
 * Time: 20:37
 */

namespace AA\Widget;
use AA\FormArray;
use AA_Value;

/** 2FA Auth - generate secret widget */
class TfaWidget extends Widget
{

    /** name function
     *
     */
    public function name(): string
    {
        return _m('2FA Secret generator');
    }

    function requireLibs($aa_property, $winfo) {
        return ['aa-jslib'];  // for AA_SendWidgetAjax
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
        global $auth;
        $base_name = FormArray::getName4Form($aa_property->getId(), $content, $this->item_index);
        $base_id = FormArray::formName2Id($base_name);

        $widget = "\n<div id=\"ajaxv_$base_id\" data-aa-alias=\"". myspecialchars(base64_encode('AA_2FA_QR')) ."\">";
        $widget .= "\n<div class=\"aa-2fa-changeform aa-widget aa-widget-2fa\" id=\"widget-$base_id\">";

        $input_name = $base_name . "[tfa][usr]";
        $input_id = FormArray::formName2Id($input_name);
        $widget .= "\n<div class=aa-2fa-username><input type=text readonly id=\"$input_id\" name=\"$input_name\" placeholder=\"" . _m('Username') . "\" value=\"{$auth->auth['uname']}\"></div>";

        $input_name = $base_name . "[tfa][pwd]";
        $input_id = FormArray::formName2Id($input_name);
        $widget .= "\n<div class=aa-2fa-password><input type=password id=\"$input_id\" name=\"$input_name\" placeholder=\"" . _m('Password') . "\" autocomplete=\"current-password\"></div>";

        $input_name = $base_name . "[tfa][otc]";
        $input_id = FormArray::formName2Id($input_name);
        $widget .= "\n<div class=aa-2fa-otc><input type=text id=\"$input_id\" name=\"$input_name\" placeholder=\"" . _m('One time Code') . "\" inputmode=\"numeric\"  pattern=\"[0-9]*\" autocomplete=\"one-time-code\"></div>";

        $hidden_name = $base_name . "[tfa][gen]";
        $hidden_id = FormArray::formName2Id($hidden_name);
        $widget .= "\n<input type=hidden id=\"$hidden_id\" name=\"$hidden_name\" value=0></div>";

        if ($help = $aa_property->getHelp()) {
            $widget .= "\n<div class=aa-help><small>$help</small></div>";
        }
        $widget .= "\n<div class=aa-2fa-buttons>";
        $widget .= "\n<input type=\"button\" value=\"" . _m('Generate New Secret') . "\" onclick=\"document.getElementById('$hidden_id').value=1;AA_SendWidgetAjax('$base_id'); return false;\">"; //Save change
        $widget .= "\n<input type=\"button\" value=\"" . _m('Get Secret') . "\" onclick=\"AA_SendWidgetAjax('$base_id'); return false;\">";
        $widget .= "\n</div>";
        $widget .= "\n</div>";
        $widget .= "\n</div>";

        return ['html' => $widget, 'last_input_name' => $input_name, 'base_name' => $base_name, 'base_id' => $base_id, 'required' => $aa_property->isRequired()];
    }


    /** @return AA_Value for the data send by the widget
     *   The data submitted by form usually looks like
     *       aa[n1_54343ea876898b6754e3578a8cc544e6][password________][pwd][new]=MyPassword
     * @param $data4field - array('pwd'=>MyPassword)
     *   This method coverts such data to AA_Value.
     */
    public static function getValue($data4field): AA_Value
    {
        $flag = $data4field['flag'] & FLAG_HTML;
        if (is_array($data4field) AND isset($data4field['usr'])) {
            return new AA_Value(ParamImplode([(($data4field['gen']==1) ? 'AA_2FASECRET' : 'AA_doNotGenerate'), $data4field['usr'], $data4field['pwd'], $data4field['otc']]), $flag);
        }
        return new AA_Value('', $flag); // ? or something else ?
    }

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {
        return Widget::propertiesShop([]);
    }

    /**
     * @inheritDoc
     */
    public function description(): string {
        return ''; // TODO: Implement description() method.
    }
}