<?php
/** PHP version 7.2+
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

// expected $view_type for both - new and edit
// expected $view_id for editing specified view or $new

use AA\IO\DB\DB_AA;

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/item.php3";     // GetAliasesFromField funct def
require_once __DIR__."/../include/discussion.php3";  // GetDiscussionAliases funct def
require_once __DIR__."/../include/msgpage.php3";

// ----------------------------------------------------------------------------
/** get_row_count function
 * @param $s
 * @param $cols
 * @param $maxrows
 * @return number of rows
 */
function get_row_count($s, $cols, $maxrows) {
    $retval = 1 + strlen ($s) / $cols;
    return ($retval > $maxrows) ?  $maxrows : $retval;
}

/** show_digest_filters function
 *  Shows the top part of the Alerts Selection Set view
 */
function show_digest_filters() {
    global $view_id;
    $db = getDB();

    // Show the radio buttons "Group by selection"
    $db->query("SELECT aditional, aditional3 FROM view WHERE id=". ($view_id ? $view_id : 0));
    if ($db->next_record()) {
        $group = $db->f("aditional");
        $sort  = $db->f("aditional3");
    } else {
        $group = 0;
    }

    echo "<tr><td class=\"tabtxt\"><b>"._m("Group by selections")."</b></td>
        <td class=\"tabtxt\"><b><input type=\"radio\" name=\"aditional\" value=\"1\" "
        .($group ? "checked" : "")."> ".
        _m("Yes. Write sort[] to the conds[] field for each Selection.")."<br>
        <input type=\"radio\" name=\"aditional\" value=\"0\" "
        .($group ? "" : "checked")."> ".
        _m("No. Use this sort[]:")."</b>
        <textarea name=\"aditional3\" cols=\"50\" rows=".get_row_count($sort, 50, 4).">"
        .$sort."</textarea>
        </td>
    </tr>";

    // Show the Selections
    $db->query("SELECT * FROM alerts_filter WHERE vid=".($view_id ? $view_id : 0));
    $rows = $db->num_rows();
    for ($irow = 0; $irow < $rows+2; $irow ++) {
        if ($irow <= $db->num_rows()) $db->next_record();
        $rowid = $db->f("id");
        if (!$rowid) {
            $rowid = "new$irow";
        }
        FrmInputText("filters[$rowid][description]", _m("Alerts Selection")." ".($irow+1)." "._m("Description"), $db->f("description"), 100, 50, false);
        FrmTextarea("filters[$rowid][conds]", "conds[]", $db->f("conds"),
            get_row_count($db->f("conds"), 50, 4), 50, false,
            "", "http://apc-aa.sourceforge.net/faq/#215");
    }
    FrmStaticText("", _m("If you need more selections, use 'Update' and on next Edit two empty boxes appear."));
    freeDB($db);
}

/** store_digest_filters function
 *  Stores info from the top part of the Alerts Selection Set view
 */
function store_digest_filters() {
    global $view_id, $filters, $err;
    $db = getDB ();
    if (!$view_id) {
        // "SELECT LAST_INSERT_ID() AS last_vid FROM view";
        $view_id = $db->last_insert_id();
    }
    $varset = new CVarset();

    global $aditional, $aditional3;
    $varset->clear();
    $varset->addkey("id", "number", $view_id);
    $varset->add("aditional", "number", $aditional);
    $varset->add("aditional3", "quoted", $aditional3);
    $db->query($varset->makeUPDATE("view"));

    foreach ($filters as $rowid => $filter) {
        if (!$filter["description"]) {
            if (substr ($rowid,0,3) != "new") {
                $db->query("DELETE FROM alerts_filter WHERE id=$rowid");
            }
            continue;
        }
        $varset->clear();
        $varset->add("description", "quoted", $filter["description"]);
        $varset->add("conds", "quoted", $filter["conds"]);
        $varset->add("vid", "number", $view_id);

        if (substr ($rowid,0,3) != "new") {
            $SQL = "UPDATE alerts_filter SET ". $varset->makeUPDATE() ." WHERE id='$rowid'";
            if ( !$db->query($SQL)) {
                $err["DB"] = MsgErr( _m("Can't change slice settings") );
                break;   // not necessary - we have set the halt_on_error
            }
        } else {
            if ( !$varset->doINSERT('alerts_filter')) {
                $err["DB"] = MsgErr(_m("Can't change slice settings"));
                break;   // not necessary - we have set the halt_on_error
            }
        }
    }
    freeDB($db);
}

// ----------------------------------------------------------------------------
/** OrderFrm function
 * @param $name
 * @param $txt
 * @param $val
 * @param $order_fields
 * @param $easy_order=false
 */
function OrderFrm($name, $txt, $val, $order_fields, $easy_order=false, $group=false) {
    global $vw_data;
    $name=safe($name); $txt=safe($txt);

    $order_type = $easy_order ?
        ['0'=>_m("Ascending"), '1' => _m("Descending")] :
        ['0'=>_m("Ascending"), '1' => _m("Descending"), '2' => _m("Ascending by Priority"), '3' => _m("Descending by Priority")];
    echo "<tr><td class=\"tabtxt\"><b>$txt</b> ";
    echo "</td>\n<td>";
    FrmSelectEasy($name, $order_fields, $val);
    // direction variable name - construct from $name
    $dirvarname = substr($name,0,1).substr($name,-1)."_direction";
    FrmSelectEasy($dirvarname, $order_type, $vw_data[$dirvarname]);
    if ( $group ) {
        FrmSelectEasy("gb_header", getViewGroupFunctions(), $vw_data['gb_header']);
    }
    //  PrintHelp($hlp);
    echo "</td></tr>\n";
}

/** OrderFrm function
 * @param $name
 * @param $txt
 * @param $val
 */
function OrderAliasFrm($name, $txt, $val) {
    global $vw_data;
    $name=safe($name); $txt=safe($txt);

    $order_type = ['0'=>_m("Ascending"), '1' => _m("Descending"), '4'=>_m("Ascending (numeric)"),     '5' => _m("Descending (numeric)")];
    echo "<tr><td class=\"tabtxt\"><b>$txt</b> ";
    echo "</td>\n<td><input name=\"$name\" size=\"14\" maxlength=\"10\" value=\"". safe($val) ."\">";
    $dirvarname = substr($name,0,1).substr($name,-1)."_direction";
    FrmSelectEasy($dirvarname, $order_type, $vw_data[$dirvarname]);
    echo "<div class=tabhlp>"._m("you can sort items by alias instead of fields, but it is very slow (computed for all the items)")."</div></td></tr>\n";
}


/** ConditionForm function
 * @param $name
 * @param $txt
 * @param $val
 */
function ConditionFrm($name, $txt, $val) {
    global $lookup_fields, $lookup_op, $vw_data;
    $name=safe($name); $txt=safe($txt);
    echo "<tr><td class=\"tabtxt\"><b>$txt</b> ";
    echo "</td>\n<td>";
    FrmSelectEasy($name, $lookup_fields, $val);
    // direction variable name - construct from $name
    $opvarname = substr($name,0,5)."op";
    FrmSelectEasy($opvarname, $lookup_op, $vw_data[$opvarname]);
    // direction variable name - construct from $name

    $condvarname = substr($name,0,5)."cond";
    echo "<input type=\"text\" name=\"$condvarname\" size=\"50\" maxlength=\"254\" value=\"". safe($vw_data[$condvarname]) ."\">";

    // PrintHelp($hlp);
    echo "</td></tr>\n";
}


/** OrderFrm function
 * @param $name
 * @param $txt
 * @param $val
 */
function BitFlagFrm($name, $txt, $val) {
    FrmInputChBox( $name, $txt, $val&1);
    FrmInputChBox( $name, $txt, $val&2);
    FrmInputChBox( $name, $txt, $val&4);
    FrmInputChBox( $name, $txt, $val&8);
}

$view_id = (int) $_REQUEST['view_id'];  // sanitize
if ($view_id) {
    $view      = AA_Views::getViewNumeric($view_id);
    $view_type = $view->f('type');
} else {
    // fix for Zeus webserver, which (at least in version 4.2 on PHP 4.3.1/SunOS)
    // adds wrong $view_type variable to $_SERVER array, which then redefine
    // the $view_type from $_POST array
    $view_type = $_POST['view_type'] ?: $_GET['view_type'];
}


$back_url = ( (($view_type == 'categories') OR ($view_type == 'links')) ?
                get_aa_url('modules/links/modedit.php3') :
                get_admin_url('se_views.php3') );

if ($cancel) {
    go_url( $back_url );
}

// If you try to edit a view in different slice, you should jump there
if ($view_id) {
    if (unpack_id($view->f('slice_id')) != $slice_id) {
        go_url($view->jumpUrl());
    }
}

if (!IfSlPerm(PS_FULLTEXT)) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You do not have permission to change views"), "admin");
    exit;
}

$err = [];          // error array (Init - just for initializing variable
$varset      = new Cvarset();
$p_slice_id  = q_pack_id($slice_id);


$VIEW_FIELDS     = getViewFields();
$VIEW_TYPES      = getViewTypes();
$VIEW_TYPES_INFO = getViewTypesInfo();

if ( $update ) {
    do {
        foreach ($VIEW_FIELDS as $k => $v) {
            if ( $v["validate"] AND $VIEW_TYPES[$view_type][$k] ) {
                ValidateInput($k, $VIEW_TYPES[$view_type][$k], $$k, $err, false, $v["validate"]);
            }
        }
        if (count($err)) {
            break;
        }

        $varset->add("slice_id", "unpacked", $slice_id);
        //$varset->add("name", "quoted", substr($name,0,30));
        $varset->add("type", "quoted", $view_type);

        foreach ($VIEW_FIELDS as $k => $v) {
            if ( $VIEW_TYPES[$view_type][$k] ) {
                $insval = $_POST[$k];
                if ($v["type"] == "bool") {
                    $insval  = $insval  ? 1 : 0;
                }
                if ($v["max"]) {
                    $insval  = substr($insval, 0, $v["max"]);
                }
                $varset->add($k, 'quoted', quote($insval));
            }
        }

        if ( $view_id ) {
            $varset->addkey('id','number', $view_id);
            if ( !$varset->doUpdate('view', null, "V.$view_id") ) {  // track history
                $err["DB"] = MsgErr( _m("Can't change slice settings") );
                break;   // not necessary - we have set the halt_on_error
            }
        } else {
            if ( !$varset->doInsert('view')) {
                $err["DB"] = MsgErr( _m("Can't insert into view.") );
                break;   // not necessary - we have set the halt_on_error
            }
            $view_id = $varset->last_insert_id();
        }
        AA::Pagecache()->invalidateFor($slice_id);  // invalidate old cached values

        foreach ($VIEW_TYPES[$view_type] as $k => $v) {
            if (substr ($k,0,strlen("function:")) == "function:") {
                $show_fn = "store_".substr($k,strlen("function:"));
                $show_fn();
            }
        }
        // go_url( $back_url );
    } while(false);

    if ( !count($err) ) {
        $Msg = MsgOk(_m("View successfully changed"));
        // in order we reread the data from the database (see below)
        unset($update);
    }
}

if ( !$update ) {  // set variables from database
    $vw_data = [];
    if ( $view_id ) {
        // edit specified view data
        $vw_data = DB_AA::select1('', 'SELECT * FROM view', [['id', $view_id, 'i']]);
    } elseif ( $new_templ AND $view_view) {
        // new view from template
        $vw_data = DB_AA::select1('', 'SELECT * FROM view', [['id', $view_view, 'i']]);
    } elseif ( $view_type == 'inputform') {
        // new view - inputform
        $vw_data['odd'] = GetInputFormTemplate(); // get current input form template
    } elseif ( $view_type ) {
        // new view - get default values from view table -
        //            take first view of the same type
        $vw_data = DB_AA::select1('', 'SELECT * FROM view', [['type', $view_type]], ['id']);
    } else {        // error - someone switched the slice or so
        go_url($back_url);
    }

    if ( $vw_data ) {
        $view_type = $vw_data['type'];
        // fix for old view type
        if ($view_type=='script') {
            $view_type = 'javascript';
        }
    } else {
        $vw_data = ["listlen" => 10];   // default values
    }
} else {        // updating - load data into vw_data array
    foreach ($VIEW_FIELDS as $k => $v) {
        if ( $VIEW_TYPES[$view_type][$k] ) {
            $vw_data[$k] = $$k;
        }
    }
}

// operators array
$lookup_op = [
    "<"  => "<",
                    "<=" => "<=",
                    "="  => "=",
                    "==" => "== (exact match)",
                    "<>" => "<>",
                    ">"  => ">",
                    ">=" => ">=",
                    "LIKE"  => "substring (LIKE)",
                    "RLIKE"  => "begins with ... (RLIKE)",
                    "ISNULL"  => "not set",
                    "NOTNULL"  => "is set",
                    "m:<" => "< now() - x [in seconds]",
                    "m:>" => "> now() - x [in seconds]"
];

// lookup group of constatnts
$lookup_groups = GetConstants('lt_groupNames', 'name');

// lookup slice fields
$lookup_fields[''] = " ";  // default - none
if ( $VIEW_TYPES_INFO[$view_type]['fields'] ) {
    $field_func = $VIEW_TYPES_INFO[$view_type]['fields'];
    $lookup_fields += $field_func();
} else {
    $serchbar = new AA_Searchbar();

    $lookup_fields = AA_Fields::getFields4Select($slice_id, false, 'name', true, true);
}
$slice = AA_Slice::getModule($slice_id);
//huhl($lookup_fields, $slice->getSearchfields());


$headcode = <<<'EOT'
<script>
    function InitPage() {
      EnableClick('even_odd_differ','even_row_format')
      EnableClick('category_sort','category_format')
    }
    function EnableClick(cond,what) {
      document.f[what].disabled = !(document.f[cond].checked);
    }
</script>
EOT;


$mainmenu = 'sliceadmin';
$submenu  = 'views';
switch ( $view_type ) {
    case 'categories':
        $mainmenu = "modadmin";
        $submenu =  $view_id ? "view$view_id" : "newcatview";
        break;
    case 'links':
        $mainmenu = "modadmin";
        $submenu =  $view_id ? "view$view_id" : "newlinkview";
        break;
}

$script2run = <<<'EOT'
   $('textarea').each(function(index, elem){
      CodeMirror.fromTextArea(elem, { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" });
   });
EOT;

$apage = new AA_Adminpageutil($mainmenu, $submenu);

if (($view_type == 'categories') OR ($view_type == 'links')) {
    $apage->setModuleMenu('modules/links');
}

$apage->setTitle(_m("Admin - design View"));
if ($VIEW_TYPES[$type]["even_odd_differ"]) {
    $apage->addRequire('InitPage();', 'AA_Req_Load');
}
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->addRequire('jquery');  // for $ used in $script2run
$apage->addRequire('codemirror@5');
$apage->addRequire($script2run, 'AA_Req_Load');
$apage->printHead($err, $Msg);

$form_buttons = [
    'update',
                       'cancel'    => ["url"=>"se_views.php3"],
                       'view_id'   => ['value'=> $view_id],
                       'view_type' => ['value'=> $view_type],
];

// Print View Form ----------
FrmTabCaption(_m("Defined Views"), $form_buttons);

$view_url = AA_INSTAL_URL. "view.php3?vid=$view_id";
//FrmStaticText(_m("Id"), "<a href=\"$view_url\" title=\"". _m('show this view') ."\">$view_id</a>", false, '', '', false );

foreach ($VIEW_TYPES[$view_type] as $k => $v) {
    if (substr ($k,0,strlen("function:")) == "function:") {
        $show_fn = "show_".substr($k,strlen("function:"));
        $show_fn();
    }
    // we can define default values for fields (see constants.php3)
    if ( !($value = $vw_data[$k]) && $VIEW_TYPES_INFO[$view_type][$k]['default'] ) {
        $value = $VIEW_TYPES_INFO[$view_type][$k]['default'];
    }

    $input = $VIEW_FIELDS[$k]["input"];

    if (is_array($v)) {
        $label = $v["label"];
        $help  = $v["help"];
        if ($v["input"]) {
            $input = $v["input"];
        }
    } else {
        $label = $v;
        $help  = "";
    }

    // Create quick link to views
    if ( !$help ) {
        $help = AA_View::getViewJumpLinks($value);
    }

    switch( $input ) {
        case "field":   FrmInputText(  $k, $label, $value, 254, 50, false, $help); break;
        case "field50": FrmInputText(  $k, $label, $value, 50, 50, true, $help); break;   // for name
        case "vid":     FrmInputText(  $k, $label. " - <a href=\"$view_url\" title=\"". _m('show this view') ."\">$view_id</a>", $value,  15, 15, false, $help, '', false, 'text', '', '[a-z][a-z_0-9]+'); break;
        case "area":    FrmTextarea(   $k, $label, $value,   4, 50, false, $help, '', 1); break;
        case "areabig": FrmTextarea(   $k, $label, $value,  15, 80, false, $help, '', 1); break;
        case "seltype": FrmInputSelect($k, $label, $VIEW_TYPES_INFO[$view_type]['modification'], $value, false, $help); break;
        case "selfld":  FrmInputSelect($k, $label, $lookup_fields, $value, false, $help); break;
        case "selgrp":  FrmInputSelect($k, $label, $lookup_groups, $value, false, $help); break;
        case "op":      FrmInputSelect($k, $label, $lookup_op, $value, false, $help); break;
        case "chbox":   FrmInputChBox( $k, $label, $value, false, '', 1, false, $help); break;
        case "bitflag": BitFlagFrm( $k, $label, $value); break;
        case "cond":    ConditionFrm(  $k, $label, $value); break;
        case "order":   OrderFrm(      $k, $label, $value, $lookup_fields, $VIEW_TYPES_INFO[$view_type]['order'] == 'easy'); break;
        case "orderal": OrderAliasFrm( $k, $label, $value); break;
        case "group":   OrderFrm(      $k, $label, $value, $lookup_fields, $VIEW_TYPES_INFO[$view_type]['order'] == 'easy', true); break;
        case "select":  FrmInputSelect($k, $label, $VIEW_FIELDS[$k]['value'], $vw_data[$k], false, $help, ''); break;
        case "none":    break;
    }
}

$slice = AA_Slice::getModule($slice_id);

switch( $VIEW_TYPES_INFO[$view_type]['aliases'] ) {
    case 'disc2mail':   PrintAliasHelp(GetDiscussion2MailAliases(), false, false, $form_buttons);
    case 'discus':      PrintAliasHelp(GetDiscussionAliases(), false, false, $form_buttons);
                        break;
    case 'field':       PrintAliasHelp($slice->aliases($VIEW_TYPES_INFO[$view_type]['aliases_additional']), $slice->getFields()->getRecordArray(), false, $form_buttons);
                        break;
    case 'justids':     PrintAliasHelp($slice->aliases(false, 'justids'), false, false, $form_buttons);
                        break;
    case 'links':
    case 'categories':
    case 'const':       PrintAliasHelp(GetAliases4Type($VIEW_TYPES_INFO[$view_type]['aliases']), false, false, $form_buttons);
                        break;
    case 'none':        break;
}
FrmTabEnd();

$view_id2show = $view_id ?: '&lt;vid&gt;';

$view_recipe1 = '';
$view_recipe2 = '';
if ($view) {
    $set = $view->getSet();
    if ($view_conds   = $set->getCondsAsString()) {
        $view_sort    = $set->getSortAsString();
        $view_recipe1 = '&lcub;view:' . $view_id . ':-&lcub;ids:'. $view->getSliceId(). $view_id . ':'. $view_conds . ($view_sort ? ':'.$view_sort: '') . '&rcub;&rcub;';
        $view_recipe2 = '&lcub;view.php3?vid=' . $view_id . '&cmd[' . $view_id . ']=' . (new AA_Set(null, $view->getConds()))->getCondsAsString() . ($view_sort ? '&set[' . $view_id . ']=sort-'.$view_sort: '').'&rcub;';
    }
}

$apage->addHelpbox(_m('To include view in your sitemodule webpage use'), '<pre>
       &lcub;view:'.$view_id2show.'&rcub;
       &lcub;view.php3?vid='.$view_id2show.'&rcub;
       '.$view_recipe1.'
       '.$view_recipe2.'
     </pre>
     <a href="https://documentation.actionapps.org/en/Syntax#.7Bview.7D_-_Including_Views">'._m('See documentation').'</a>');
$apage->printHelpbox();

$apage->printFoot();
