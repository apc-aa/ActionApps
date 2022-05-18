<?php
/** expected $slice_id for edit slice, nothing for adding slice
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;
use AA\Widget\Widget;

//const AA_REGISTER_GLOBALS = true;
require_once __DIR__."/../include/init_page.php3";
mgettext_bind(get_mgettext_lang(), 'param_wizard');
require_once __DIR__."/../include/constants_param_wizard.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/msgpage.php3";

try {
    // array ( 'PUB_DATE' => 1, 'USERNAME' => 5, 'EMAIL___' => 1, 'HEADLINE' => 5, '' => 20, 'NENENENE' => 6, 'MOUCHAAA' => 1, 'UNDEFINE' => 4, )
    $alias1 = InputVars::stringSlashedPOST('alias1'); // empty
    // array ( 'j. n. Y' => 1, '<a href="_#…' => 5, '!:<a href="…' => 1, '' => 31, 'Kvakoš 765…' => 5, )
    $alias1_func = InputVars::stringSlashedPOST('alias1_func'); // empty
    // array ( 'f_d' => 1, 'f_t' => 7, 'f_c' => 1, 'f_h' => 8, '' => 21, 'f_w' => 5, )
    $alias1_func_f = InputVars::stringSlashedPOST('alias1_func_f'); // empty
    // array ( 'alias pro D…' => 1, 'Username' => 5, 'Email' => 1, '- Nadpis' => 2, 'alias for I…' => 3, '' => 22, 'Kvakoš 765…' => 4, 'alias for S…' => 1, 'alias undef…' => 4, )
    $alias1_help = InputVars::stringSlashedPOST('alias1_help'); // empty
    // array ( '' => 34, 'EN_TRANS' => 2, 'HEADLINK' => 3, 'MAMHLADA' => 1, 'LKNCJAKL' => 2, 'SRC_URL#' => 1, )
    $alias2 = InputVars::stringSlashedPOST('alias2'); // empty
    // array ( '' => 36, '{index:head…' => 2, '<a href="_#…' => 3, 'Kvakoš 765…' => 1, 'source_href…' => 1, )
    $alias2_func = InputVars::stringSlashedPOST('alias2_func'); // empty
    // array ( 'f_a' => 6, 'f_0' => 1, 'f__' => 2, 'f_t' => 3, '' => 29, 'f_k' => 1, 'f_l' => 1, )
    $alias2_func_f = InputVars::stringSlashedPOST('alias2_func_f'); // empty
    // array ( '' => 38, 'item title,…' => 3, 'Kvakoš 765…' => 1, 'alias for S…' => 1, )
    $alias2_help = InputVars::stringSlashedPOST('alias2_help'); // empty
    // array ( '' => 40, 'SEO_URL_' => 3, )
    $alias3 = InputVars::stringSlashedPOST('alias3'); // empty
    // array ( '' => 40, 'https://eko…' => 3, )
    $alias3_func = InputVars::stringSlashedPOST('alias3_func'); // empty
    // array ( 'f_a' => 6, 'f_0' => 1, 'f_t' => 5, '' => 31, )
    $alias3_func_f = InputVars::stringSlashedPOST('alias3_func_f'); // empty
    // array ( '' => 43, )
    $alias3_help = InputVars::stringSlashedPOST('alias3_help'); // empty
    // array ( 0 => 37, 3 => 6, )
    $feed = InputVars::intPOST('feed');
    // array ( 'publish_dat…' => 3, 'headline...…' => 13, 'con_email..…' => 3, 'time.......…' => 1, 'seo........…' => 33, 'relation...…' => 4, 'source.....…' => 1, 'category...…' => 4, )-array ( 'publish_dat…' => 1, 'headline...…' => 10, 'con_email..…' => 1, 'seo........…' => 26, 'source.....…' => 1, 'category...…' => 4, )
    $fid = InputVars::stringSlashedGETPOST('fid'); // -
    // array ( 'on' => 10, )
    $html_default = InputVars::boolPOST('html_default'); // alpha
    // array ( 'on' => 10, )
    $html_show = InputVars::boolPOST('html_show'); // alpha
    // array ( '' => 36, 'Kvakoš 765…' => 7, )
    $input_before = InputVars::stringSlashedPOST('input_before'); // empty
    // array ( '' => 23, 'Kvakoš 765…' => 20, )
    $input_default = InputVars::stringSlashedPOST('input_default'); // empty
    // array ( 'now' => 1, 'txt' => 21, 'log' => 21, )
    $input_default_f = InputVars::stringSlashedPOST('input_default_f'); // alpha
    // array ( 'Datum uve' => 1, 'Reader\'s Us…' => 5, 'Reader\'s e-…' => 1, '' => 20, 'Headline of…' => 3, 'Kvakoš 765…' => 8, 'Source of t…' => 1, 'Category' => 4, )
    $input_help = InputVars::stringSlashedPOST('input_help'); // empty
    // array ( 'qte' => 31, 'num' => 1, 'co2' => 11, )
    $input_insert_func_f = InputVars::stringSlashedPOST('input_insert_func_f'); // alnum
    // array ( '' => 31, 'Kvakoš 765…' => 12, )
    $input_insert_func_p = InputVars::stringSlashedPOST('input_insert_func_p'); // empty
    // array ( '' => 36, 'Kvakoš 765…' => 7, )
    $input_morehlp = InputVars::stringSlashedPOST('input_morehlp'); // empty
    // array ( 0 => 41, '#sLiCe-4429…' => 1, 'v486f77206f…' => 1, )
    $input_show_func_c = InputVars::stringSlashedPOST('input_show_func_c');
    // array ( 'dte' => 1, 'fld' => 35, 'sel' => 1, 'txt' => 2, 'mch' => 3, 'mse' => 1, )
    $input_show_func_f = InputVars::stringSlashedPOST('input_show_func_f'); // alpha
    // array ( '1:10:1:2' => 1, '' => 12, 'Džusík s …' => 2, 'Kvakoš 765…' => 24, '::::::{"1"#…' => 3, ':::::::{"1"…' => 1, )
    $input_show_func_p = InputVars::stringSlashedPOST('input_show_func_p'); // empty
    // array ( 'date' => 1, 'unique' => 5, 'e-unique' => 3, 'text' => 17, 'bool' => 1, 'pwd' => 16, )
    $input_validate_f = InputVars::stringSlashedPOST('input_validate_f');
    // array ( '' => 21, ':' => 1, 'con_email..…' => 1, 'headline...…' => 2, 'Kvakoš 765…' => 18, )
    $input_validate_p = InputVars::stringSlashedPOST('input_validate_p'); // empty
    // array ( 'Přihlásit' => 4, )
    $submit = InputVars::boolPOST('submit');
    // array ( 1 => 27, 0 => 15, )
    $text_stored = InputVars::boolPOST('text_stored');
    // array ( 'on' => 15, )
    $translation = InputVars::boolPOST('translation'); // alpha

    $return_url = InputVars::stringGETPOST('return_url');
    $upd_edit = InputVars::boolPOST('upd_edit');
    // array ( 1 => 42, ' Změnit [^…' => 1, )
    $update = InputVars::boolPOST('update');
    $cancel = InputVars::boolPOST('cancel');
    $err = InputVars::arrayGETPOST('err');
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

if ($upd_edit) {
    $back_admin_url = get_admin_url('se_inputform.php', ['fid'=>$fid]);
} else {
    $back_admin_url = get_admin_url('se_fields.php3', (AA_Fields::isSliceField($fid) ? ['slice_fields'=>1] : []));
}

if ($cancel) {
    go_url($back_admin_url);
}

if (!IfSlPerm(PS_FIELDS)) {
    MsgPageMenu(get_admin_url("index.php3"), _m("You have not permissions to change fields settings"), "admin");
    exit;
}

$err = [];          // error array (Init - just for initializing variable
$varset = new Cvarset();

/** QVaidateInput function
 * @param $variabeName
 * @param $inputName
 * @param $variable
 * @param $err (by link)
 * @param $needed
 * @param $type
 */
function QValidateInput($variableName, $inputName, $variable, &$err, $needed, $type) {
    if ($variable !== null) {
        ValidateInput($variableName, $inputName, $variable, $err, $needed, $type);
    }
}

/** Qvarsetadd function
 * @param $varname
 * @param $type
 * @param $value
 */
function Qvarsetadd($varname, $type, $value) {
    global $varset;
    if ($value !== null) {
        $varset->add($varname, $type, $value);
    }
}

/** get_params function
 * Finds the first ":" and fills the part before ":" into $fnc, after ":" into $params.
 *   (c) Jakub, 28.1.2003
 * @param $src
 * @param $fnc (by link)
 * @param $params (by link)
 */
function get_params($src, &$fnc, &$params) {
    if (strchr ($src,":")) {
        $params = substr ($src, strpos ($src,":")+1);
        $fnc = substr ($src, 0, strpos ($src,":"));
    }
    else {
        $params = "";
        $fnc = $src;
    }
}

if ($update) {
    do {
        QValidateInput("input_before",    _m("Before HTML code"),    $input_before,      $err, false, "text");
        QValidateInput("input_help",      _m("Help for this field"), $input_help,        $err, false, "text");
        QValidateInput("input_morehlp",   _m("More help"),           $input_morehlp,     $err, false, "text");
        QValidateInput("input_default",   _m("Default"),             $input_default,     $err, false, "text");
        QValidateInput("input_show_func", _m("Input show function"), $input_show_func_f, $err, false, "text");

        $alias_err = _m("Alias must be always _# + 8 UPPERCASE letters, e.g. _#<b>SOMTHING</b>.");
        for ($iAlias = 1; $iAlias <= 3; $iAlias ++) {
            $alias_var = "alias".$iAlias;
            $alias_name = $_POST[$alias_var] ? (($_POST[$alias_var.'_disable'] == 1) ? 'X#' : '_#').$_POST[$alias_var] : '';

            QValidateInput("alias".$iAlias, _m("Alias")." ".$iAlias, $alias_name, $err, false, "alias");
            $alias_var = "alias".$iAlias."_help";
            QValidateInput("alias".$iAlias."_help", $alias_err.$iAlias, $$alias_var, $err, false, "text");
            $alias_var = "alias".$iAlias."_func";
            QValidateInput("alias".$iAlias."_func", _m("Function").$iAlias, $$alias_var, $err, false, "text");

            Qvarsetadd("alias".$iAlias, "quoted", $alias_name);
            Qvarsetadd("alias".$iAlias."_help", "quoted", $GLOBALS["alias".$iAlias."_help"]);
            Qvarsetadd("alias".$iAlias."_func", "quoted", $GLOBALS["alias".$iAlias."_func_f"].":".$GLOBALS["alias".$iAlias."_func"]);
        }

        

        if (count($err)) {
            break;
        }
        Qvarsetadd("input_before",  "quoted", $input_before);
        Qvarsetadd("input_help",    "quoted", $input_help);
        Qvarsetadd("input_morehlp", "quoted", $input_morehlp);
        Qvarsetadd("input_default", "quoted", "$input_default_f:$input_default");
        // mark as multiple
        // Mark field as multiple is not necessary - we can remove this
        // property in the future. Honza, 2006-10-17
        // On the other hand we use it for marking the field as translatable
        // Honza, 2014-03-24

        /** @var Widget $widget_class */
        $widget_class = Widget::constructClassName($input_show_func_f);
        Qvarsetadd("multiple",      "quoted", ($translation ? 2 : 0) + ($widget_class::multiple() ? 1 : 0));

        // setting input show function

        // the constants are packed in order it could be easily passed
        // to another script
        $input_show_func_c_real = ($input_show_func_c[0] == 'v') ? pack_id(substr($input_show_func_c,1)) : $input_show_func_c;
        $isf_parameters = $widget_class::getClassProperties();
        $isf            = $input_show_func_f;
        if (isset($isf_parameters['const'])) {
            $isf .= ':'.$input_show_func_c_real;
            // if we use relation between slices, then the validation should be 'id'
            // and insert function shoud be ids (which is most important mainly
            // for "Related Item Window" - because without it, the IDS are prefixed by x, y or z)
            if (substr($input_show_func_c_real,0,7)=='#sLiCe-') {
                // allowed insert functions are ids, and both computed functions
                if (!in_array($input_insert_func_f, ['ids','com','co2'])) {
                    $input_insert_func_f = 'ids';
                    $input_insert_func_p = '';
                }
                $input_validate_f    = 'id';
                $input_validate_p    = '';

            }
        }
        $isf     .= ':'.$input_show_func_p;

        Qvarsetadd("input_show_func", "quoted", $isf);
        Qvarsetadd("input_validate", "quoted", "$input_validate_f:$input_validate_p");
        Qvarsetadd("feed", "quoted", "$feed");

        // setting input insert function
        $iif="$input_insert_func_f:$input_insert_func_p";
        Qvarsetadd("input_insert_func", "quoted", "$iif");
        Qvarsetadd("html_default", "number", ($html_default ? 1 : 0));
        Qvarsetadd("html_show", "number", ($html_show ? 1 : 0));
        Qvarsetadd("text_stored", "number", $text_stored);

        $varset->addkey('id', 'text', $fid);
        $varset->addkey('slice_id', 'unpacked', $slice_id);
        $varset->doUpdate('field', null, "F".AA_Fields::createShortId($fid).'@'.pack_id($slice_id));
//        $SQL = "UPDATE field SET ". $varset->makeUPDATE() ." WHERE id='$fid' AND slice_id='$p_slice_id'";
//
//        if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
//            $err["DB"] = MsgErr("Can't change field");
//            break;
//        }
        AA::Pagecache()->invalidateFor($slice_id);  // invalidate old cached values

        if ( !count($err) ) {
            $Msg = MsgOk(_m("Fields update successful"));
            go_url( $return_url ? (expand_return_url(1) . "&msg=".urlencode($Msg)) : $back_admin_url );  // back to field page
        }
    } while (0);           //in order we can use "break;" statement
}

  // lookup constants
$constants['AA_OPTGROUP 1'] = _m('Constant Group');
$constants[] = "";   // add blank constant as the first option

// we encode the value so it could be passed to another script easily
// (javascript escape is not good solution - it is incompatible with php
// urldecode()
foreach ( GetConstants('lt_groupNames', 'name') as $val => $name ) {
    $constants[varname4form($val)] = $name;
}

$constants['AA_OPTGROUP 2'] = _m('Slices');

// add slices to constant array (good for related stories, link to authors ...)
foreach ($g_modules as $k => $v) {
    if (($v['type'] == 'S') || ($v['type'] == 'Alerts')) {
        $constants["#sLiCe-".$k] =  $v['name'];
    }
}
  // lookup fields
if ( !($fld = DB_AA::select1('', 'SELECT * FROM field', [['slice_id', $slice_id, 'l'], ['id', $fid]]))) {
    $Msg = MsgErr(_m("No fields defined for this slice"));
    go_url($return_url ? expand_return_url(1) : $back_admin_url);  // back to field page
}

if ( !$update ) {      // load defaults
    $input_before  = $fld['input_before'];
    $input_help    = $fld['input_help'];
    $input_morehlp = $fld['input_morehlp'];

    for ($iAlias = 1; $iAlias <= 3; $iAlias ++) {
        $GLOBALS["alias".$iAlias]            = substr($fld["alias".$iAlias],2);
        $GLOBALS["alias".$iAlias."_disable"] = (substr($fld["alias".$iAlias],0,2)=='X#');
        $GLOBALS["alias".$iAlias."_help"]    = $fld["alias".$iAlias."_help"];
        get_params($fld["alias".$iAlias."_func"], $GLOBALS["alias".$iAlias."_func_f"], $GLOBALS["alias".$iAlias."_func"]);
    }

    get_params($fld["input_default"], $input_default_f, $input_default);
    get_params($fld["input_insert_func"], $input_insert_func_f, $input_insert_func_p);
    get_params($fld["input_validate"], $input_validate_f, $input_validate_p);

    // switching type of show
    get_params($fld["input_show_func"], $input_show_func_f, $input_show_func_p);

    // which parameters uses this widget?
    $widget_class   = Widget::constructClassName($input_show_func_f);
    $isf_parameters = $widget_class::getClassProperties();

    if ( isset($isf_parameters['const'])) {
        get_params($input_show_func_p, $input_show_func_c_real, $input_show_func_p);
        $input_show_func_c = (substr($input_show_func_c_real,0,7) == '#sLiCe-') ? $input_show_func_c_real : varname4form($input_show_func_c_real);
    }

    $html_default = $fld["html_default"];
    $html_show    = $fld["html_show"];
    $feed         = $fld["feed"];
    $text_stored  = $fld["text_stored"];
    $translation  = $fld["multiple"] & 2;
}


$script2run = '
   var opts = { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" };
   window.cm_a1 = CodeMirror.fromTextArea(document.getElementById("alias1_func"), opts);
   window.cm_a2 = CodeMirror.fromTextArea(document.getElementById("alias2_func"), opts);
   window.cm_a3 = CodeMirror.fromTextArea(document.getElementById("alias3_func"), opts);
   window.cm_h1 = CodeMirror.fromTextArea(document.getElementById("alias1_help"), opts);
   window.cm_h2 = CodeMirror.fromTextArea(document.getElementById("alias2_help"), opts);
   window.cm_h3 = CodeMirror.fromTextArea(document.getElementById("alias3_help"), opts);
   window.cm_ih = CodeMirror.fromTextArea(document.getElementById("input_help"), opts);
   window.cm_ib = CodeMirror.fromTextArea(document.getElementById("input_before"), opts);
   ';

$headcode = '
<script>
  function CallConstantEdit(as_new) {
    var conid = document.f.input_show_func_c.value
    if ( conid.substring(0,7) == \'#sLiCe-\' ) {
      if (as_new) {
        alert(\''. _m("You selected slice and not constant group. It is impossible to create slice from here.") .'\');
        return;
      }
      document.location = GetUrl("'. get_admin_url("index.php3"). '", ["change_id=" + conid.substring(7) ]);  
      return;
    }
    if ( conid != "" ) {
      let url = "'. get_admin_url('se_constant.php3', ['return_url' => 'se_inputform.php', 'fid' => $fid]). '";
      url += ( (as_new != 1) ? "&group_id=" : "&as_new=") + escape(conid);
      document.location=url;
    }
  }
  /* Calls the parameters wizard. Parameters are as follows:
    list = name of the array containing all the needed data for the wizard
    combo_list = a combobox of which the selected item will be shown in the wizard
    text_param = the text field where the parameters are placed
  */
  function CallParamWizard(list, combo_list, text_param ) {
    combo_list_el = document.f.elements[combo_list];
    page = GetUrl("'. get_admin_url('param_wizard.php3') .'", ["list=" + list, "combo_list=" + combo_list, "text_param=" + text_param, "item=" + combo_list_el.value]);
    param_wizard = window.open(page,"somename","width=450,scrollbars=yes,menubar=no,hotkeys=no,resizable=yes");
    param_wizard.focus();
  }
  
  function ResetAlias(number) {
      document.querySelector("input[name=alias"+number+"]").value = "";
      document.querySelector("select[name=alias"+number+"_func_f]").value = "";
      if (number == 1) { window.cm_a1.setValue(""); }
      if (number == 2) { window.cm_a2.setValue(""); }
      if (number == 3) { window.cm_a3.setValue(""); }
      document.querySelector("textarea[name=alias"+number+"_help]").value = "";
  }
</script>';

$apage = new AA_Adminpageutil('sliceadmin','fields');
$apage->setTitle(_m("Admin - configure Fields"));
$apage->setSubtitle(_m("WARNING: Do not change this setting if you are not sure what you're doing!"));
$apage->addRequire('codemirror@5');
$apage->addRequire('aa-jslib');   // just for AA_Message() copy to clipboard
$apage->addRequire(get_aa_url('javascript/js_lib.min.js?v='.AA_JS_VERSION, '', false));
$apage->addRequire(get_aa_url('css/aa-responsive.css?v='.AA_JS_VERSION, '', false )); // for clipboard button positions


$apage->addRequire($script2run, 'AA_Req_Load');
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->printHead($err, $Msg);

$form_buttons = [
    "update"   => ["type"=>"hidden","value"=>"1"],
                      "fid"      => ["type"=>"hidden", "value"=>$fid],
                      "update",
                      "upd_edit" => ["type"=>"submit", "value"=>_m("Update & Edit")],
                      "cancel2"   => ['value'=>_m('Show History'), "url"=>\AA\Util\ChangesMonitor::getHistoryUrl("F".AA_Fields::createShortId($fid).'@'.pack_id($slice_id))],
                      "cancel"   => ["url"=>"se_fields.php3" .(AA_Fields::isSliceField($fid) ? '?slice_fields=1':'')]
];

FrmTabCaption(_m("Field properties") . ': ' . safe($fld['name'] . ' (' . $fld['id'] . ')'), $form_buttons);

$isc_arr = AA_Components::getClassArray('AA\\Widget\\Widget');

$tab_separator = "\n<tr><td colspan=2><hr></td></tr>\n";

echo "
     <tr>
      <td class=tabtxt><b>". _m("Input type") ."</b></td>
      <td class=tabtxt>";
       FrmSelectEasy("input_show_func_f", $isc_arr, $input_show_func_f);

      echo "<div class=tabhlp>". _m("Input field type in Add / Edit item.") ."</div>
            <table border=0 cellspacing=0 cellpadding=4 bgcolor=\"". COLOR_TABBG ."\">
             <tr>
              <td><b>". _m("Constants") ."</b> ";
               FrmSelectEasy("input_show_func_c", $constants, $input_show_func_c);
      $constants_menu = explode('|', str_replace(" ","&nbsp;",_m("Edit|Use as new|New")));
      echo "   <div class=tabhlp>". _m("Choose a Constant Group or a Slice.") ."</div>
              </td>
              <td>
                <a href='javascript:CallConstantEdit(0)'>&larr;&nbsp;". $constants_menu[0] ."</a><br>
                <a href='javascript:CallConstantEdit(1)'>&larr;&nbsp;". $constants_menu[1] ."</a><br>
                <a href='". get_admin_url('se_constant.php3', ['return_url' => 'se_inputform.php', 'fid' => $fid]). "'>". $constants_menu[2] ."</a>
              </td>
             </tr>
            </table>
            <table border=0 cellpadding=0 cellspacing=0 width=\"100%\">
                <tr><td class=tabtxt><b>"._m("Parameters")."</b></td>
                <td class=tabhlp><a href='javascript:CallParamWizard (\"INPUT_TYPES\",\"input_show_func_f\",\"input_show_func_p\")'><b>"
                 ._m("Help: Parameter Wizard")."</b></a></td></tr></table>
                <textarea name=\"input_show_func_p\" rows=4 cols=50 wrap=\"virtual\">". safe($input_show_func_p) ."</textarea>
      </td>
      </tr>";
FrmInputChBox("translation", _m("Allow translation"), $translation, false, '', 1, false, _m('Only Text Area and Text Field widgets allows translation. Languages should be set on Slice Setting page.'));

echo $tab_separator;
echo "
     <tr>
      <td class=tabtxt><b>". _m("Default") ."</b></td>
      <td class=tabtxt>";
        // txt and qte is the same, so it is better to use the only one
        if ($input_default_f == 'qte') {
            $input_default_f = 'txt';
        }
        FrmSelectEasy("input_default_f", AA_Components::getClassArray('AA_Generator_'), $input_default_f);
      echo "<div class=tabhlp>". _m("How to generate the default value") ."</div>
            <table border=0 cellpadding=0 cellspacing=0 width=\"100%\">
                <tr><td class=tabtxt><b>"._m("Parameters")."</b></td>
                <td class=tabhlp><a href='javascript:CallParamWizard (\"DEFAULT_VALUE_TYPES\",\"input_default_f\",\"input_default\")'><b>"
                 ._m("Help: Parameter Wizard")."</b></a></td></tr></table>
            <input type=\"text\" name=\"input_default\" class=aa-w-full value=\"". safe($input_default) ."\">
      </td>
     </tr>";
echo $tab_separator;
echo "
     <tr>
         <td class=tabtxt><b>". _m("Validate") ."</b></td>
         <td class=tabtxt>";
         FrmSelectEasy("input_validate_f", getSelectBoxFromParamWizard($VALIDATE_TYPES), $input_validate_f);
         echo "<table border=0 cellpadding=0 cellspacing=0 width=\"100%\">
             <tr><td class=tabtxt><b>"._m("Parameters")."</b></td>
             <td class=tabhlp><a href='javascript:CallParamWizard (\"VALIDATE_TYPES\",\"input_validate_f\",\"input_validate_p\")'><b>"
             ._m("Help: Parameter Wizard")."</b></a></td></tr></table>
         <input type=\"text\" name=\"input_validate_p\" class=aa-w-full value=\"". safe($input_validate_p) ."\">
         </td>
      </td>
     </tr>";
echo $tab_separator;
echo "
     <tr>
         <td class=tabtxt><b>". _m("Stored as") ."</b></td>
         <td class=tabtxt>";
         if ($fld['in_item_tbl']) {
             echo _m('Stored in <em>item</em> table - it is not possible to change store method');
         } else {
             $text_rows    = DB_AA::select1('cnt', "SELECT count(*) as cnt FROM content, item", [['content.item_id', 'item.id', 'j'], ['item.slice_id', $slice_id, 'l'], ['content.field_id', $fld['id']], ['content.flag', 64, 'set']]);
             $numeric_rows = DB_AA::select1('cnt', "SELECT count(*) as cnt FROM content, item", [['content.item_id', 'item.id', 'j'], ['item.slice_id', $slice_id, 'l'], ['content.field_id', $fld['id']], ['content.flag', 64, 'unset']]);
             FrmSelectEasy("text_stored", ['1'=>_m('Text'), '0'=>_m('Number')], $text_stored);
             echo "<div class=tabhlp>"._m('Text').": $text_rows, "._m('Number').": $numeric_rows</div>";
         }
         echo "
         </td>
      </td>
     </tr>";
echo $tab_separator;
echo "
     <tr>
         <td class=tabtxt><b>". _m("Insert") ."</b></td>
         <td class=tabtxt>";
         FrmSelectEasy("input_insert_func_f", AA_Components::getClassArray('AA_Inserter_'), $input_insert_func_f);
         echo "<div class=tabhlp>"._m("Defines how value is stored in database.")."</div>
         <table border=0 cellpadding=0 cellspacing=0 width=\"100%\">
             <tr><td class=tabtxt><b>"._m("Parameters")."</b></td>
             <td class=tabhlp><a href='javascript:CallParamWizard (\"INSERT_TYPES\",\"input_insert_func_f\",\"input_insert_func_p\")'><b>"
             ._m("Help: Parameter Wizard")."</b></a></td></tr></table>
         <textarea name=\"input_insert_func_p\" rows=3 cols=50 wrap=\"virtual\">". safe($input_insert_func_p) ."</textarea>
         </td>
     </tr>";
echo $tab_separator;

FrmInputChBox("html_show", _m("Show 'HTML' / 'plain text' option"), $html_show);
FrmInputChBox("html_default", _m("'HTML' as default"), $html_default, false, '', 1, false, _m('HTML option means, that the field is printed as filled using {field...........} alias. Plain text means the newlines are converted to &lt;br&gt; etc. HTML is good option for any nontextual data, at least.'));
FrmTextarea(  "input_help", _m("Help for this field"), $input_help, 2, 50, false, _m("Shown help for this field"));
FrmInputText("input_morehlp", _m("More help"), $input_morehlp, 254, '', false,  _m("Text shown after user click on '?' in input form"));
FrmTextarea(  "input_before", _m("Before HTML code"), $input_before, 4, 50, false, _m("Code shown in input form before this field"));

echo $tab_separator;
echo "
     <tr>
      <td class=tabtxt><b>". _m("Feeding mode") ."</b></td>
      <td class=tabtxt>";
        FrmSelectEasy("feed", inputFeedModes(), $feed);
      echo "<div class=tabhlp>". _m("Should the content of this field be copied to another slice if it is fed?") ."</div>
      </td>
     </tr>";
FrmTabSeparator(_m("ALIASES used in views to print field content (%1)", [$fld['id']]));


$myarray = $FIELD_FUNCTIONS['items'];
foreach ($myarray as $key => $val) {
    $func_types[$key] = $key." - ".$val['name'];
}
//asort($func_types);

for ($iAlias=1; $iAlias <= 3; ++$iAlias) {
    $alias_name    = "alias$iAlias";
    $alias_func_f  = $alias_name. "_func_f";
    $alias_func    = $alias_name. "_func";
    $alias_help    = $alias_name. "_help";
    $alias_disable = $alias_name. "_disable";
    $alias_value   = $$alias_name;
    $alias_id_att  = strlen($alias_value) ? "id=\"alias$alias_value\"" : '';
    $alias_hlp     = "<strong><a href='javascript:CallParamWizard(\"FIELD_FUNCTIONS\", \"$alias_func_f\", \"$alias_func\")'>"._m("Help: Parameter Wizard")."</a></strong>";
    $disbled_chb   = FrmChBoxEasyCode($alias_disable, $$alias_disable, '', 1);

    $aname_row     = "<div class=\"flex f-baseline\">";
    $aname_row    .=  "<div>_#<input name=\"$alias_name\" $alias_id_att type=text size=16 maxlength=8 value=\"$alias_value\" pattern=\"[A-Z0-9_#]{8}\" placeholder=\""._m("8 CHARS"). "\" title=\""._m("8 UPPERCASE letters or _")."\"></div>";
    $aname_row    .=  "<label class=\"sz-xs pr-1 pl-1\">$disbled_chb"._m('Disabled')."</label>";
    $aname_row    .=  "<button type=button onclick=\"navigator.clipboard.writeText('_#'+document.querySelector('input[name=$alias_name]').value).then(() => { AA_Message('Copied to clipboard','info'); });\" class=\"btn-s\" title=\""._m('Copy to clipboard')."\">";
    $aname_row    .=   '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="#584011" viewBox="0 0 16 16"><path d="M9.5 0a.5.5 0 0 1 .5.5.5.5 0 0 0 .5.5.5.5 0 0 1 .5.5V2a.5.5 0 0 1-.5.5h-5A.5.5 0 0 1 5 2v-.5a.5.5 0 0 1 .5-.5.5.5 0 0 0 .5-.5.5.5 0 0 1 .5-.5h3Z"></path><path d="M3 2.5a.5.5 0 0 1 .5-.5H4a.5.5 0 0 0 0-1h-.5A1.5 1.5 0 0 0 2 2.5v12A1.5 1.5 0 0 0 3.5 16h9a1.5 1.5 0 0 0 1.5-1.5v-12A1.5 1.5 0 0 0 12.5 1H12a.5.5 0 0 0 0 1h.5a.5.5 0 0 1 .5.5v12a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5v-12Z"></path><path d="M10.854 7.854a.5.5 0 0 0-.708-.708L7.5 9.793 6.354 8.646a.5.5 0 1 0-.708.708l1.5 1.5a.5.5 0 0 0 .708 0l3-3Z"></path></svg>'; // https://icons.getbootstrap.com/
    $aname_row    .=  "</button>";
    $aname_row    .=  "<input type=button value=\""._m('Reset')."\" class=\"btn-s\" onclick=\"ResetAlias($iAlias)\">";
    $aname_row    .= "</div>";

    FrmStaticText(_m("Alias")." $iAlias", $aname_row,'',"", false);
    FrmInputSelect($alias_func_f, _m("Function"), $func_types, $$alias_func_f, false, $alias_hlp);
    FrmTextarea($alias_func, _m("Parameters"), $$alias_func, 8, 60, false, AA_View::getViewJumpLinks('view.php3?'.$$alias_func)); // added for f_v parameters
    FrmTextarea($alias_help, _m("Description"), $$alias_help, 2, 0);
    if ($iAlias != 3) {
        echo "\n    <tr><td colspan=2><hr></td></tr>";
    }
}

FrmTabEnd($form_buttons);

$apage->printFoot();
