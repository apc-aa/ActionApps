<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

// expected $slice_id for edit slice, nothing for adding slice

require_once "../include/init_page.php3";
bind_mgettext_domain($GLOBALS['AA_INC_PATH']."lang/".get_mgettext_lang()."_param_wizard_lang.php3", false, get_mgettext_lang());
require_once $GLOBALS['AA_INC_PATH']."constants_param_wizard.php3";
require_once $GLOBALS['AA_INC_PATH']."formutil.php3";
require_once $GLOBALS['AA_INC_PATH']."varset.php3";
require_once $GLOBALS['AA_INC_PATH']."pagecache.php3";
require_once $GLOBALS['AA_INC_PATH']."msgpage.php3";

function EditConstantURL() {
    global $fld, $sess;
    if (substr($fld['id'],0,8)== "category") {
        return con_url($sess->url(self_base(). "se_constant.php3"), "categ=1");
    } else {
        return $sess->url(self_base(). "se_constant.php3");
    }
}

if ($cancel) {
    go_url($sess->url(self_base(). "./se_fields.php3"));
}

if (!IfSlPerm(PS_FIELDS)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

if ($del) {
    $SQL = "DELETE FROM field WHERE id='$fid' AND slice_id='$p_slice_id'";
    if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
        $err["DB"] = MsgErr("Can't change field");
        break;
    }
    $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");  // invalidate old cached values

    $Msg = MsgOK(_m("Field delete OK"));
    go_url($sess->url("./se_fields.php3"));  // back to field page
}

$INPUT_SHOW_FUNC_TYPES = inputShowFuncTypes();

// If $onlyupdate is set, then just set fields defined Note exceptiosn
//      $input_default is as to store in the database, i.e. input_default_f:$input_default
//      $alias1_func is as to store in database ie alias1_func_f:alias1_func (same for 2 and 3)
//      $input_show_func instead of $input_show_func_f:$input_show_func_p (and some other variations)
//      $input_insert_func instead of $input_insert_func_f:$input_insert_func_p etc
//      $input_validate instead of $input_validate_f:$input_validate_p
//      $html_show as in db, not as returned by HTML check
//      $text_stored instead of function of $input_validate_f

// Extend ValidateInput to NOT check if field is not present
function QValidateInput($variableName, $inputName, $variable, &$err, $needed, $type) {
    global $onlyupdate;
    if (! $onlyupdate || ($variable !== null)) {
        ValidateInput($variableName, $inputName, $variable, $err, $needed, $type);
    }
}

function Qvarsetadd($varname, $type, $value) {
    global $varset,$onlyupdate;
    if (! $onlyupdate || ($value !== null)) {
        $varset->add($varname, $type, $value);
    }
}

if ($update) {
    do {
        QValidateInput("input_before", _m("Before HTML code"), $input_before, $err, false, "text");
        QValidateInput("input_help", _m("Help for this field"), $input_help, $err, false, "text");
        QValidateInput("input_morehlp", _m("More help"), $input_morehlp, $err, false, "text");
        QValidateInput("input_default", _m("Default"), $input_default, $err, false, "text");
        QValidateInput("input_show_func", _m("Input show function"), $input_show_func_f, $err, false, "text");

        $alias_err = _m("Alias must be always _# + 8 UPPERCASE letters, e.g. _#SOMTHING.");
        for ($iAlias = 1; $iAlias <= 3; $iAlias ++) {
            $alias_var = "alias".$iAlias;
            if ($$alias_var == "_#") $$alias_var = "";
            QValidateInput("alias".$iAlias, _m("Alias")." ".$iAlias, $$alias_var, $err, false, "alias");
            $alias_var = "alias".$iAlias."_help";
            QValidateInput("alias".$iAlias."_help", $alias_err.$iAlias, $$alias_var, $err, false, "text");
            $alias_var = "alias".$iAlias."_func";
            QValidateInput("alias".$iAlias."_func", _m("Function").$iAlias, $$alias_var, $err, false, "text");
        }

        if (count($err) > 1) {
            break;
        }
        // A group that only appear with onlyupdate, normally edited in se_fields
        if ($onlyupdate) {
            Qvarsetadd("name","quoted",$name);
            Qvarsetadd("input_pri","number",$input_pri);
            Qvarsetadd("input_show","number",$input_show);
        }
        Qvarsetadd("input_before", "quoted", $input_before);
        Qvarsetadd("input_help", "quoted", $input_help);
        Qvarsetadd("input_morehlp", "quoted", $input_morehlp);
        Qvarsetadd("input_default", "quoted",
            ($onlyupdate ? $input_default : "$input_default_f:$input_default"));
        Qvarsetadd("multiple","quoted",                     // mark as multiple
            ($onlyupdate ? $multiple :
            ($INPUT_SHOW_FUNC_TYPES[$input_show_func_f]['multiple'] ? 1 : 0)));

        for ($iAlias = 1; $iAlias <= 3; $iAlias ++) {
            Qvarsetadd("alias".$iAlias, "quoted", $GLOBALS["alias".$iAlias]);
            Qvarsetadd("alias".$iAlias."_help", "quoted", $GLOBALS["alias".$iAlias."_help"]);
            Qvarsetadd("alias".$iAlias."_func", "quoted",
                ($onlyupdate ? $GLOBALS["alias".$iAlias."_func"]
                : $GLOBALS["alias".$iAlias."_func_f"].":".$GLOBALS["alias".$iAlias."_func"]));
        }

        // setting input show function
        switch( $INPUT_SHOW_FUNC_TYPES[$input_show_func_f]['paramformat']) {
            case "fnc:param":
                $isf = "$input_show_func_f:$input_show_func_p";
                break;
            case "fnc:const:param":
                $isf = "$input_show_func_f:$input_show_func_c:$input_show_func_p";
                break;
            case "fnc":
            default:
                $isf = "$input_show_func_f";
        }

        // setting input insert function
        $iif="$input_insert_func_f:$input_insert_func_p";

        Qvarsetadd("input_show_func", "quoted", ($onlyupdate ? $input_show_func : "$isf"));
        Qvarsetadd("input_validate", "quoted", ($onlyupdate ? $input_validate : "$input_validate_f:$input_validate_p"));
        if (!($onlyupdate && is_null($feed))) {
            Qvarsetadd("feed", "quoted", "$feed");
        }
        Qvarsetadd("input_insert_func", "quoted", ($onlyupdate ? $input_insert_func : "$iif"));
        if (!($onlyupdate && is_null($html_default))) {
            Qvarsetadd("html_default", "number", ($html_default ? 1 : 0));
        }
        Qvarsetadd("html_show", "number", ($onlyupdate ? $html_show : ($html_show ? 1 : 0)));
        Qvarsetadd("text_stored", "number", ($onlyupdate ? $text_stored :
                                        ((($input_validate_f=="number")
                                         OR ($input_validate_f=="bool")
                                         OR ($input_validate_f=="date")) ? 0:1)));
        $SQL = "UPDATE field SET ". $varset->makeUPDATE() ." WHERE id='$fid' AND slice_id='$p_slice_id'";
        //huhl($SQL); exit;
        if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
            $err["DB"] = MsgErr("Can't change field");
            break;
        }
        $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");  // invalidate old cached values

        if ( count($err) <= 1 ) {
            $Msg = MsgOK(_m("Fields update successful"));
            go_url( ($return_url ? (expand_return_url(1) . "&msg=".urlencode($Msg)) :
                $sess->url("./se_fields.php3") ));  // back to field page
        }
    } while (0);           //in order we can use "break;" statement
}

  // lookup constants
$constants[] = "";   // add blank constant as the first option
$constants += GetConstants('lt_groupNames', 'name');

$constants[] = "";
$constants[] = "*** SLICES: ***";
$constants[] = "";

// add slices to constant array (good for related stories, link to authors ...)
foreach ($g_modules as $k => $v) {
    if ($v['type'] == 'S') {
        $constants["#sLiCe-".$k] =  $v['name'];
    }
}
  // lookup fields
$SQL = "SELECT * FROM field
         WHERE slice_id='$p_slice_id' AND id='$fid'
         ORDER BY input_pri";
$db->query($SQL);
if ( $db->next_record()) {
    $fld = $db->Record;
} else {
    $Msg = MsgErr(_m("No fields defined for this slice"));
    go_url(($return_url ? expand_return_url(1) : $sess->url("./se_fields.php3") ));  // back to field page
}

/** Finds the first ":" and fills the part before ":" into $fnc, after ":" into $params.
*   (c) Jakub, 28.1.2003 */
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

if ( !$update ) {      // load defaults
    $input_before  = $fld['input_before'];
    $input_help    = $fld['input_help'];
    $input_morehlp = $fld['input_morehlp'];

    for ($iAlias = 1; $iAlias <= 3; $iAlias ++) {
        $GLOBALS["alias".$iAlias] = $fld["alias".$iAlias];
        $GLOBALS["alias".$iAlias."_help"] = $fld["alias".$iAlias."_help"];
        get_params($fld["alias".$iAlias."_func"], $GLOBALS["alias".$iAlias."_func_f"], $GLOBALS["alias".$iAlias."_func"]);
    }

    get_params($fld["input_default"], $input_default_f, $input_default);
    get_params($fld["input_insert_func"], $input_insert_func_f, $input_insert_func_p);
    get_params($fld["input_validate"], $input_validate_f, $input_validate_p);

    // switching type of show
    get_params($fld["input_show_func"], $input_show_func_f, $input_show_func_p);
    if ( $INPUT_SHOW_FUNC_TYPES[$input_show_func_f]['paramformat']  == "fnc:const:param") {
        get_params($input_show_func_p, $input_show_func_c, $input_show_func_p);
    }

    $html_default = $fld["html_default"];
    $html_show    = $fld["html_show"];
    $feed         = $fld["feed"];
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Admin - configure Fields");?></TITLE>
<script language="JavaScript"><!--
  function CallConstantEdit(as_new) {
    var url = "<?php echo EditConstantURL(); ?>"
    var conid = document.f.input_show_func_c.options[document.f.input_show_func_c.selectedIndex].value
    if ( conid.substring(0,7) == '#sLiCe-' ) {
      alert('<?php echo _m("You selected slice and not constant group. It is unpossible to change slice. Go up in the list.") ?>');
      return;
    }
    if ( conid != "" ) {
      url += ( (as_new != 1) ? "&group_id=" : "&as_new=") + escape(conid);
      url += "&return_url=se_inputform.php3&fid=<?php echo $fid ?>";
      document.location=url;
    }
  }
  /* Calls the parameters wizard. Parameters are as follows:
    list = name of the array containing all the needed data for the wizard
    combo_list = a combobox of which the selected item will be shown in the wizard
    text_param = the text field where the parameters are placed
  */
  function CallParamWizard(list, combo_list, text_param ) {
    page = "<?php echo $sess->url(self_base()."param_wizard.php3")?>"
        + "&list=" + list + "&combo_list=" + combo_list + "&text_param=" + text_param;
    combo_list_el = document.f.elements[combo_list];
    page += "&item=" + combo_list_el.options [combo_list_el.selectedIndex].value;
    param_wizard = window.open(page,"somename","width=450,scrollbars=yes,menubar=no,hotkeys=no,resizable=yes");
    param_wizard.focus();
  }
//-->
</script>
</HEAD>
<?php
require_once $GLOBALS['AA_INC_PATH']."menu.php3";
showMenu($aamenus, "sliceadmin");

echo "<H1><B>" . _m("Admin - configure Fields") . "</B></H1>";
PrintArray($err);
echo $Msg;
echo _m("<p>WARNING: Do not change this setting if you are not sure what you're doing!</p>");

$form_buttons = array("update"=>array("type"=>"hidden","value"=>"1"),
                      "fid"=>array("type"=>"hidden", "value"=>$fid),
                      "update",
                      "cancel"=>array("url"=>"se_fields.php3"));

echo "
<form enctype=\"multipart/form-data\" method=post action=\"". $sess->url($PHP_SELF) ."\" name=\"f\">";
FrmTabCaption(_m("Field properties"). ': '. safe($fld['name']. ' ('.$fld['id']. ')'),
              '','',$form_buttons, $sess, $slice_id);
echo "
  <tr>
   <td>
     <tr>
      <td class=tabtxt><b>". _m("Input type") ."</b></td>
      <td class=tabtxt colspan=3>";
       FrmSelectEasy("input_show_func_f", $INPUT_SHOW_FUNC_TYPES, $input_show_func_f);

      echo "<div class=tabhlp>". _m("Input field type in Add / Edit item.") ."</div>
            <table border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">
             <tr>
              <td class=tabtit><b>". _m("Constants") ."</b> ";
               FrmSelectEasy("input_show_func_c", $constants, $input_show_func_c);
      $constants_menu = explode('|', str_replace(" ","&nbsp;",_m("Edit|Use as new|New")));
      echo "   <div class=tabtit>". _m("Choose a Constant Group or a Slice.") ."</div>
              </td>
              <td class=tabtit>
                <font class=tabtxt><b>&lt;&nbsp;<a href='javascript:CallConstantEdit(0)'>"
                    . $constants_menu[0] ."</a></b></font><br>
                <span class=tabtxt><b>&lt;&nbsp;<a href='javascript:CallConstantEdit(1)'>"
                    . $constants_menu[1] ."</a></b></span><br>
                <span class=tabtxt>
                    <B><a href='". con_url(EditConstantURL(),"return_url=se_inputform.php3&fid=$fid"). "'>". $constants_menu[2] ."</a></B></span>
              </td>
             </tr>
            </table>
            <table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\">
                <tr><td class=tabtxt><b>"._m("Parameters")."</b></td>
                <td class=tabhlp><a href='javascript:CallParamWizard (\"INPUT_TYPES\",\"input_show_func_f\",\"input_show_func_p\")'><b>"
                 ._m("Help: Parameter Wizard")."</b></a></td></tr></table>
            <input type=\"Text\" name=\"input_show_func_p\" size=50 maxlength=240 value=\"". safe($input_show_func_p) ."\">
      </td>
     </tr>
     <tr><td colspan=4><hr></td></tr>
     <tr>
      <td class=tabtxt><b>". _m("Default") ."</b></td>
      <td class=tabtxt colspan=3>";
        // txt and qte is the same, so it is better to use the only one
        if ($input_default_f == 'qte') {
            $input_default_f = 'txt';
        }
        FrmSelectEasy("input_default_f", getSelectBoxFromParamWizard ($DEFAULT_VALUE_TYPES), $input_default_f);
      echo "<div class=tabhlp>". _m("How to generate the default value") ."</div>
            <table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\">
                <tr><td class=tabtxt><b>"._m("Parameters")."</b></td>
                <td class=tabhlp><a href='javascript:CallParamWizard (\"DEFAULT_VALUE_TYPES\",\"input_default_f\",\"input_default\")'><b>"
                 ._m("Help: Parameter Wizard")."</b></a></td></tr></table>
            <input type=\"Text\" name=\"input_default\" size=50 value=\"". safe($input_default) ."\">
      </td>
     </tr>
     <tr><td colspan=4><hr></td></tr>
     <tr>
         <td class=tabtxt><b>". _m("Validate") ."</b></td>
         <td class=tabtxt colspan=3>";
         FrmSelectEasy("input_validate_f", getSelectBoxFromParamWizard ($VALIDATE_TYPES),
            $input_validate_f);
         echo "<table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\">
             <tr><td class=tabtxt><b>"._m("Parameters")."</b></td>
             <td class=tabhlp><a href='javascript:CallParamWizard (\"VALIDATE_TYPES\",\"input_validate_f\",\"input_validate_p\")'><b>"
             ._m("Help: Parameter Wizard")."</b></a></td></tr></table>
         <input type=\"Text\" name=\"input_validate_p\" size=50 maxlength=240 value=\"". safe($input_validate_p) ."\">
         </td>
      </td>
     </tr>
     <tr><td colspan=4><hr></td></tr>
     <tr>
         <td class=tabtxt><b>". _m("Insert") ."</b></td>
         <td class=tabtxt colspan=3>";
         FrmSelectEasy("input_insert_func_f", getSelectBoxFromParamWizard($INSERT_TYPES),
            $input_insert_func_f);
         echo "<div class=tabhlp>"._m("Defines how value is stored in database.")."</div>
         <table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" width=\"100%\">
             <tr><td class=tabtxt><b>"._m("Parameters")."</b></td>
             <td class=tabhlp><a href='javascript:CallParamWizard (\"INSERT_TYPES\",\"input_insert_func_f\",\"input_insert_func_p\")'><b>"
             ._m("Help: Parameter Wizard")."</b></a></td></tr></table>
         <input type=\"Text\" name=\"input_insert_func_p\" size=50 maxlength=240 value=\"". safe($input_insert_func_p) ."\">
         </td>
     </tr>
     <tr><td colspan=4><hr></td></tr>
     <tr>
      <td class=tabtxt><b>HTML</b></td>
      <td class=tabtxt colspan=3>
        <input type=\"checkbox\" name=\"html_show\"". ($html_show ? " checked" : "") .">
        <b>". _m("Show 'HTML' / 'plain text' option") ."</b><br>
        <input type=\"checkbox\" name=\"html_default\"". ($html_default ? " checked" : "") .">
        <b>". _m("'HTML' as default") ."</b>
      </td>
     </tr>
     <tr>
      <td class=tabtxt><b>". _m("Help for this field") ."</b></td>
      <td class=tabtxt colspan=3><input type=\"Text\" name=\"input_help\" size=50 maxlength=254 value=\"". safe($input_help). "\">
      <div class=tabhlp>". _m("Shown help for this field") ."</div>
      </td>
     </tr>
     <tr>
      <td class=tabtxt><b>". _m("More help") ."</b></td>
      <td class=tabtxt colspan=3><input type=\"Text\" name=\"input_morehlp\" size=50 maxlength=254 value=\"". safe($input_morehlp) ."\">
      <div class=tabhlp>". _m("Text shown after user click on '?' in input form") ."</div>
      </td>
     </tr>
     <tr>
      <td class=tabtxt><b>". _m("Before HTML code") ."</b></td>
      <td class=tabtxt colspan=3><textarea name=\"input_before\" rows=4 cols=50 wrap=virtual>". safe($input_before) ."</textarea>
      <div class=tabhlp>". _m("Code shown in input form before this field") ."</div>
      </td>
     </tr>
     <tr><td colspan=4><hr></td></tr>
     <tr>
      <td class=tabtxt><b>". _m("Feeding mode") ."</b></td>
      <td class=tabtxt colspan=3>";
        FrmSelectEasy("feed", inputFeedModes(), $feed);
      echo "<div class=tabhlp>". _m("Should the content of this field be copied to another slice if it is fed?") ."</div>
      </td>
     </tr>";
FrmTabSeparator(_m("ALIASES used in views to print field content"));

$myarray = $FIELD_FUNCTIONS['items'];
foreach ($myarray as $key => $val) {
    $func_types[$key] = $key." - ".$val['name'];
}
asort($func_types);

for ($iAlias=1; $iAlias <= 3; ++$iAlias) {
    $alias_name    = "alias$iAlias";
    $alias_func_f  = $alias_name. "_func_f";
    $alias_func    = $alias_name. "_func";
    $alias_help    = $alias_name. "_help";
    $alias_value   = $$alias_name ? $$alias_name : '_#';
    $alias_hlp     = "<strong><a href='javascript:CallParamWizard(\"FIELD_FUNCTIONS\", \"$alias_func_f\", \"$alias_func\")'>"._m("Help: Parameter Wizard")."</a></strong>";
    FrmInputText($alias_name, _m("Alias")." $iAlias", $alias_value, 10, 20, false, _m("_# + 8 UPPERCASE letters or _"));
    FrmInputSelect($alias_func_f, _m("Function"), $func_types, $$alias_func_f, false, $alias_hlp);
    FrmInputText($alias_func, _m("Parameters"), $$alias_func, 250, 60);
    FrmInputText($alias_help, _m("Description"), $$alias_help, 254, 60);
    if ($iAlias != 3) {
        echo "\n    <tr><td colspan=2><hr></td></tr>";
    }
}

FrmTabEnd($form_buttons, $sess, $slice_id);

echo "\n </form>";
HtmlPageEnd();
page_close();
?>
