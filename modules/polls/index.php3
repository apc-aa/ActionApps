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

// APC AA - Module main administration page

// used in init_page.php3 script to include config.php3 from the right directory
$directory_depth = '../';

require_once "../../include/init_page.php3";
require_once AA_INC_PATH ."varset.php3";
require_once AA_INC_PATH ."formutil.php3";
require_once AA_INC_PATH ."msgpage.php3";


// id of the editted module
$module_id = $slice_id;               // id in long form (32-digit hexadecimal
// number)
$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database

$polls_info = GetModuleInfo($module_id,'P');


// $r_admin_order, $r_admin_order - controls article ordering
// $r_admin_order contains field id
// $r_admin_order_dir contains 'd' for descending order, 'a' for ascending
if (!isset($r_admin_order) OR $change_id){ // we are here for the first time
    // or we are switching to another slice
    // set default admin interface settings from user's profile
    $r_admin_order = 'startDate';
    $r_admin_order_dir = "d";
    $sess->register(r_admin_order);
    $sess->register(r_admin_order_dir);
}

// Check permissions for this page.
// You should change PS_MODP_EDIT_POLLS permission to match the permission in your
// module. See /include/perm_core.php3 for more details

if ( !IfSlPerm(PS_MODP_EDIT_POLLS) ) {
    MsgPage($sess->url(self_base())."index.php3", _m("No permissions to edit polls"));
    exit;
}


/************************************************************\
* Moves items between bins
\************************************************************/
function MoveItems($chb,$status) {
    global $db, $auth;
    if ( isset($chb) AND is_array($chb) ) {
        $poll_ids = "";
        reset( $chb );
        while ( list(,$it_id) = each( $chb ) ) {
            if ($poll_ids) $poll_ids .= ",";
            $poll_ids .= "'".$it_id."'";
        }
        if ($poll_ids) {
            $SQL = "UPDATE polls SET
            status_code = $status WHERE pollID IN ($poll_ids)";
            $db->tquery ($SQL);
        }
        // substr removes first 'x'
        //    $cache = new PageCache(CACHE_TTL); // database changed -
        //    $cache->invalidateFor("slice_id=$slice_id");  // invalidate old cached values
    }
}


echo "----------".$action;

if ($action) {
    switch( $action ) {  // script post parameter
        case "app":
        if (!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_POLLS2ACT)) {
            MsgPageMenu($sess->url(self_base())."index.php3", _m("No permissions to move polls to Active bin"), "items");
            exit;
        }
        MoveItems($sP,1);
        break;
        case "hold":
        if (!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_POLLS2HOLD)) {
            MsgPageMenu($sess->url(self_base())."index.php3", _m("No permissions to move polls to Hold bin"), "items");
            exit;
        }
        MoveItems($sP,2);
        break;
        case "trash":
        if (!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_POLLS2TRASH)) {
            MsgPageMenu($sess->url(self_base())."index.php3", _m("No permissions to move polls to Trash bin"), "items");
            exit;
        }
        MoveItems($sP,3);
        break;
        case "filter":  // edit the first one
        $r_admin_order = ( $admin_order ? $admin_order : "startDate" );
        $r_admin_order_dir = ( $admin_order_dir ? "d" : "a");
        break;

    }
} // end if ($action)

if ($Delete == "trash") {         // delete feeded items in trash bin
    // feeded items we can easy delete
    if (!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_DELETE_POLLS )) {
        MsgPageMenu($sess->url(self_base())."index.php3", _m("No permissions to delete polls"), "items");
        exit;
    }
    // delete polls of item fields
    $db->query("DELETE FROM polls
    WHERE status_code=3 AND id = '$p_module_id'");

}


// fill code for handling the operations managed on this page

switch( $Tab ) {
    case "app":   $r_bin_state = "app";   break;
    case "appb":  $r_bin_state = "appb";  break;
    case "appc":  $r_bin_state = "appc";  break;
    case "hold":  $r_bin_state = "hold";  break;
    case "trash": $r_bin_state = "trash"; break;
}

$now = now();

// count polls in each bin -----------
$item_bin_cnt[1]=$item_bin_cnt[2]=$item_bin_cnt[3]=0;
$db->query("SELECT status_code, count(*) as cnt FROM polls
            WHERE id = '$p_module_id'
            AND defaults=0
            GROUP BY status_code");
while ( $db->next_record() )
$item_bin_cnt[ $db->f(status_code) ] = $db->f(cnt);

$db->query("SELECT count(*) as cnt FROM polls
            WHERE id = '$p_module_id'
            AND status_code=1
            AND defaults=0
            AND endDate <= '".$now."' ");
if ( $db->next_record() )
$item_bin_cnt_exp = $db->f(cnt);

$db->query("SELECT count(*) as cnt FROM polls
            WHERE id = '$p_module_id'
            AND status_code=1
            AND defaults=0
            AND endDate > '".$now."' ");
if ( $db->next_record() )
$item_bin_cnt_pend = $db->f(cnt);



HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Polls manager"); ?></title>
</head>
<script language="JavaScript" type="text/javascript">
<!--

/************************************************************\
*  Fills 'action' hidden field and submits the form
\************************************************************/
function SubmitItems(act) {
    document.itemsform.action.value = act
    document.itemsform.submit()
}

/************************************************************\
*
\************************************************************/
function MarkedActionGo() {
    var ms = document.itemsform.markedaction_select;
    switch( ms.options[ms.selectedIndex].value ) {
        case "1-app": SubmitItems('app');
        break;
        case "2-hold": SubmitItems('hold');
        break;
        case "3-trash": SubmitItems('trash');
        break;
    }
}
/************************************************************\
*
\************************************************************/
function SelectVis() {
    var len = document.itemsform.elements.length
    state = 2
    for ( var i=0; i<len; i++ ) {
        if ( document.itemsform.elements[i].name.substring(0,2) == 'sP') { //polls checkboxes
            if (state == 2) {
                state = ! document.itemsform.elements[i].checked;
            }
            document.itemsform.elements[i].checked = state;
        }
    }
}
//-->
</script>
<?php

$db = new DB_AA;

require_once "util.php3";   // module specific utils

require_once AA_BASE_PATH."modules/polls/menu.php3";
showMenu($aamenus, "pollsmanager", $r_bin_state, $navbar != "0", $leftbar != "0");

//fields for sort selectbox
$polls_fields = array( 'pollID'=>_m('Id'),
'pollTitle'=>_m('Question'),
'startDate'=>_m('Publish date'),
'endDate'=>_m('Expiry date'));

if ( !($polls_fields[$r_admin_order] ))   // bad value in $r_admin_order - set to default
$r_admin_order = 'startDate';

$SQL = "SELECT * FROM polls WHERE (id='".q_pack_id($module_id)."') AND (defaults=0) AND ";
switch( $r_bin_state ) {
    case "app":   $SQL .= "(status_code=1) AND (endDate > '".$now."')";   break;
    case "appc":  $SQL .= "(status_code=1) AND (endDate <= '".$now."')";  break;
    case "hold":  $SQL .= "(status_code = 2)";                            break;
    case "trash": $SQL .= "(status_code = 3)";                            break;
}
$SQL .= " ORDER BY $r_admin_order ". ($r_admin_order_dir == "d" ? "DESC" : "ASC");


// user definend sorting and filtering (add by setu 2002-0206)
if ($sort_filter != "0") {
    // action URL with return_url if $return_url is set.
    echo '<form name=filterform method=post action="'. $sess->url($PHP_SELF). '">
    <table width="490" border="0" cellspacing="0" cellpadding="0"
    class=leftmenu bgcolor="'. COLOR_TABBG .'">';

    //order
    echo "<tr><td class=search>&nbsp;
    <a href='javascript:document.filterform.submit()'>
    <img src='../../images/order.gif' alt='"._m("Order")."' border=0></a>&nbsp;&nbsp;<b>"
    ._m("Order")."</b></td><td class=leftmenuy>";
    FrmSelectEasy('admin_order', $polls_fields, $r_admin_order, "onchange='document.filterform.submit()'");
    echo "<input type=hidden name=action value='filter'>";
    echo "<input type='checkbox' name='admin_order_dir' onchange='document.filterform.submit()'".
    ( ($r_admin_order_dir=='d') ? " checked> " : "> " ) . _m("Descending"). "</td></tr>";

    echo "</table></form><p></p>"; // workaround for align=left bug
}


echo "
<form name=itemsform method=post action=\"". $sess->url($PHP_SELF)."\">
<input type=\"hidden\" name=\"action\" value=\"\">"; // filled by javascript function SubmitItem
if ($r_admin_order_dir == "d") {
    echo "    <input type=\"hidden\" name=\"admin_order_dir\" value=\"on\">";
}

echo "<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"1\" bgcolor=\"". COLOR_TABTITBG ."\" align=\"center\">
<tr><td>
<table width=\"500\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\" bgcolor=\"". COLOR_TABBG ."\">
<tr><td width=\"5%\" class=tabtit>&nbsp;</td>
<td width=\"10%\" class=tabtit><b>ID</b></td>
<td width=\"45%\" class=tabtit><b>". _m("Question") ."</b></td>
<td width=\"20%\" class=tabtit><b>". _m("Publish date")."</b></td>
<td width=\"20%\" class=tabtit><b>". _m("Expiry date")."</b></td></tr>";

$db->query($SQL);
$cnt=0;
while ($db->next_record()) {
    $cnt++;
    $vars = $db->Record;
    showOnePollTitle($module_id, $vars["pollID"], $vars["pollTitle"], $vars["startDate"], $vars["endDate"], $r_admin_order_dir);
}

if ($cnt == 0) {
    echo "<tr><td colspan=5 class=tabtxt>". _m("No item found")."</td></tr>";
}
elseif ($action_selected != "0") {
    echo "<tr><td colspan=5 class=tabtxt>";

    if ( ($r_bin_state != "app")  AND
    ($r_bin_state != "appb") AND
    ($r_bin_state != "appc") AND
    CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_POLLS2ACT))
    $markedaction["1-app"] = _m("Move to Active bin");

    if ( ($r_bin_state != "hold") AND
    CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_POLLS2HOLD))
    $markedaction["2-hold"] = _m("Move to Holding bin");

    if ( ($r_bin_state != "trash") AND
    CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_POLLS2TRASH))
    $markedaction["3-trash"] = _m("Move to Trash");

    if (is_array($markedaction) && count ($markedaction)) {
        echo "<img src='".AA_INSTAL_PATH."images/arrow_ltr.gif'>
        <a href='javascript:SelectVis()'>"._m("Select all")."</a>&nbsp;&nbsp;&nbsp;&nbsp;";

        // click "go" does not use markedform, it uses itemsfrom above...
        // maybe this action is not used.
        echo "<select name='markedaction_select'>
        <option value=\"nothing\">"._m("Selected polls").":";

        reset($markedaction);
        while (list($k, $v) = each($markedaction))
        echo "<option value=\"". htmlspecialchars($k)."\"> ".
        htmlspecialchars($v);
        echo "</select>&nbsp;&nbsp;<a href=\"javascript:MarkedActionGo()\" class=leftmenuy>"._m("Go")."</a>";
    }
    echo "</td></tr>";
}

echo "</table></td></tr></table>";
echo "</form>";


echo '
</body>
</html>';

page_close();
exit;

?>