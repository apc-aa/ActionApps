<?php  #slice_id expected
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

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH] . "varset.php3";
require $GLOBALS[AA_INC_PATH] . "view.php3";
require $GLOBALS[AA_INC_PATH] . "pagecache.php3";
require $GLOBALS[AA_INC_PATH] . "item.php3";
require $GLOBALS[AA_INC_PATH] . "feeding.php3";
require $GLOBALS[AA_INC_PATH] . "itemfunc.php3";
require $GLOBALS[AA_INC_PATH] . "notify.php3";
require $GLOBALS[AA_INC_PATH] . "searchlib.php3";
require $GLOBALS[AA_INC_PATH] . "formutil.php3";
require $GLOBALS[AA_INC_PATH]."msgpage.php3";

$now = now();

function MoveItems($chb,$status) {
  global $db, $auth;
  if( isset($chb) AND is_array($chb) ) {
    $item_ids = "";
    reset( $chb );
    while( list($it_id,) = each( $chb ) ) {
        if ($item_ids) $item_ids .= ",";
        $item_ids .= "'".q_pack_id(substr($it_id,1))."'";
    }
    
    if ($item_ids) {
        $SQL = "UPDATE item SET
           status_code = $status, 
           last_edit   = '$now',
           edited_by   = '". quote(isset($auth) ? $auth->auth["uid"] : "9999999999")."'";
        
        // E-mail Alerts  
        $moved2active = $status == 1 ? time() : 0;
        $SQL .= ", moved2active = $moved2active";
       
        $SQL .= " WHERE id IN ($item_ids)"; 
        $db->tquery ($SQL);
    }
                                         // substr removes first 'x'
    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
  }
}  

function FeedAllItems($chb, $fields) {    // Feed all checked items
  global $db;
  if( isset($chb) AND is_array($chb) ) {
    reset( $chb );
    while( list($it_id,) = each( $chb ) ) {
      FeedItem( substr($it_id,1), $fields );       // substr removes first 'x'
    }  
  }
}  
# ----------------- function definition end -----------------------------------

// if there was change to another slice - reset scrollers
if(isset($r_slice_id)) {
  if($slice_id != $r_slice_id) {
    $r_slice_id = $slice_id;
    $st1="";
    $st1b="";
    $st1c="";
    $st2="";
    $st3="";
  }  
} else {
  $r_slice_id = $slice_id;
  $sess->register(r_slice_id); 
}

$p_slice_id = q_pack_id($slice_id);

$slice_info = GetSliceInfo($slice_id);

// $config_arr = unserialize( $slice_info["config"] );
// $r_bin_state - controls display of editor pages. It should be:
// app, appb, appc, hold, trash
if(!isset($r_bin_state)) {
  $r_bin_state = "app";
  $sess->register(r_bin_state); 
}

// $r_bin_show - controls complexity of display of editor pages. It should be:
// short, long
if(!isset($r_bin_show)) {
  $r_bin_show = "short";
  $sess->register(r_bin_show); 
}

// $r_admin_order, $r_admin_order - controls article ordering 
// $r_admin_order contains field id
// $r_admin_order_dir contains 'd' for descending order, 'a' for ascending
if(!isset($r_admin_order) OR $change_id){ # we are here for the first time
                                          # or we are switching to another slice
  # switch to another slice - reset settings
  if( $change_id ) {    
    $r_admin_order = "";
    $r_admin_order_dir = "";
    $r_admin_search = "";
    $r_admin_search_field = "";
  }  
                                          
  # set default admin interface settings from user's profile
  $r_admin_order = GetProfileProperty('admin_order',0);
  $r_admin_order_dir = "d";
  if( $r_admin_order ) {
    if( substr($r_admin_order,-1) == '-' )
      $r_admin_order = substr($r_admin_order,0,-1);
    if( substr($r_admin_order,-1) == '+' ) {
      $r_admin_order = substr($r_admin_order,0,-1);
      $r_admin_order_dir = "a";
    }
  }
  if( !$r_admin_order )
    $r_admin_order = "publish_date....";

  $sess->register(r_admin_order);
  $sess->register(r_admin_order_dir); 

  // $r_admin_search, $r_admin_search_field - controls article filter
  // $r_admin_search contains search string
  // $r_admin_search_field contains field id
  $foo_as = GetProfileProperty('admin_search',0);
  if( $foo_as AND (($pos=strpos($foo_as,':')) > 0) ) {
    $r_admin_search_field = substr($foo_as, 0, $pos);
    $r_admin_search = substr($foo_as, $pos+1);
  }  

  $sess->register(r_admin_search); 
  $sess->register(r_admin_search_field); 
  
  # get default number of listed items from user's profile (if not specified 
  # another number through URL)

  if( !$listlen )   
    $listlen = GetProfileProperty('listlen',0);
}

$perm_edit_all  = CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT_ALL_ITEMS);
$perm_edit_self = CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT_SELF_ITEMS);

if( !$perm_edit_all && !$perm_edit_self) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EDIT_ITEMS);
  exit;
}  

$p_slice_id= q_pack_id($slice_id);
$db2 = new DB_AA; 	 // open DB	(for subqueries)

if( $r_fields )
  $fields = $r_fields;
else
  list($fields,) = GetSliceFields($slice_id);

######## add by setu@gwtech.org 2002-0206 #######
# Options in Query Strings to call admin/index.php3 from outsite.
# by default it shows everything as original version.
# It works differently if the query string has....
#	navbar=0			// no top navigator
#	leftbar=0			// no left navigator
#	sort_filter=0			// no sort / no filter
#	action_selected=0		// no action for selected (marked) item
#	feed_selected=0			// no feed action for selected item
#	view_selected=0			// no view action for selected item
# Big switch to hide many things
#	bodyonly=1
#
if ($bodyonly == "1")
{
	// if ....="1" is set, we keep the value.
	// example: "&bodyonly=1&action_selected=1" will show action_selected....
	if (!$navbar) 	$navbar = "0";			// no top navigator
	if (!$leftbar ) $leftbar = "0";			// no left navigator
	if (!$sort_filter ) 	$sort_filter = "0";	// no sort / no filter
	if (!$action_selected )	$action_selected = "0";	// no action for selected (marked) item
	if (!$feed_selected)	$feed_selected  = "0";
	if (!$view_selected)	$view_selected  = "0";
}
######################################

if ($action) {
  switch( $action ) {  // script post parameter 
    case "app":
      if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ITEMS2ACT)) {
        MsgPageMenu($sess->url(self_base())."index.php3", L_NO_PS_MOVE_ITEMS, "items");
        exit;
      }  
      MoveItems($chb,1);
      FeedAllItems($chb, $fields);    // Feed all checked items
      break;
    case "hold":
      if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ITEMS2HOLD)) {
        MsgPageMenu($sess->url(self_base())."index.php3", L_NO_PS_MOVE_ITEMS, "items");
        exit;
      }  
      MoveItems($chb,2);
      break;
    case "trash":
      if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ITEMS2TRASH)) {
        MsgPageMenu($sess->url(self_base())."index.php3", L_NO_PS_MOVE_ITEMS, "items");
        exit;
      }  
      MoveItems($chb,3);
      break;
    case "edit":  // edit the first one
      if( isset($chb) AND is_array($chb) ) {
        reset( $chb );
        go_url(con_url($sess->url("itemedit.php3"),"encap=false&edit=1&id=")
              . substr(key($chb),1) );
      }  
      break;
    case "feed":  // feed selected items to selected slices
// huh("feed2slices:". $slices4feed);
// huh("feed2app:". $feed2app);
      $slices4feed  = split(",", $feed2slice);
      $fooappfeed = split(",", $feed2app);

      // reverse $fooappfeed array $key <--> $val
      if( isset($fooappfeed) AND is_array($fooappfeed) ) {
        reset( $fooappfeed );
        while( list(,$foo_sl_id) = each( $fooappfeed ) )
          $approvedfeed[$foo_sl_id] = true;
      }    
      if( !isset($approvedfeed) OR !is_array($approvedfeed) )
        $approvedfeed = Array();
    
      if( isset($slices4feed) AND is_array($slices4feed)) {
        reset( $slices4feed );
        while( list(,$sl_id) = each( $slices4feed ) ) {
          if( isset($chb) AND is_array($chb) ) {
            reset( $chb );
            while( list($it_id,) = each( $chb ) ) {
              $it_id = substr($it_id,1);  // remove beginning 'x'
//            huh("Item: $it_id ($slice_id) -> $sl_id <br>/n");
              FeedItemTo($it_id, $slice_id, $sl_id, $fields, ($approvedfeed[$sl_id] ? 'y':'n'), 0);
            }  
          }
        }
      }        
      break;
    case "filter":  // edit the first one
      $r_admin_order = ( $admin_order ? $admin_order : "publish_date...." );
      $r_admin_order_dir = ( $admin_order_dir ? "d" : "a");
    
      $r_admin_search = stripslashes($admin_search);
      $r_admin_search_field = $admin_search_field;
      break;
  }
  if ($return_url) { // after work for action, if return_url is there, we go to the page.
  	go_url(urldecode($return_url));
  	// Never come back....
  	exit();
  }

} // end if ($action)

// script paramerer - table switching
switch( $Tab ) {
  case "app":   $r_bin_state = "app";   break;
  case "appb":  $r_bin_state = "appb";  break;
  case "appc":  $r_bin_state = "appc";  break;
  case "hold":  $r_bin_state = "hold";  break;
  case "trash": $r_bin_state = "trash"; break;
}  

// script paramerer - display complexity switching
switch( $More ) {
  case "long":  $r_bin_show = "long";  break;
  case "short": $r_bin_show = "short"; break;
}  

if($Delete == "trash") {         // delete feeded items in trash bin
    // feeded items we can easy delete
  if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_DELETE_ITEMS )) {
    MsgPageMenu($sess->url(self_base())."index.php3", L_NO_DELETE_ITEMS, "items");
    exit;
  }  
 	$db->query("SELECT id FROM item 
               WHERE status_code=3 AND slice_id = '$p_slice_id'");
    # delete content of all fields
  while( $db->next_record() ) {   
    $db2->query("DELETE FROM content
                  WHERE item_id = '". quote($db->f(id)) ."'");  # don't worry 
  }                                      # about fed fields - content is copied
    # delete content of item fields
 	$db->query("DELETE FROM item 
             WHERE status_code=3 AND slice_id = '$p_slice_id'");

  $cache = new PageCache($db, CACHE_TTL, CACHE_PURGE_FREQ); # database changed - 
  $cache->invalidateFor("slice_id=$slice_id");  # - invalidate old cached values
}

# count items in each bin -----------
$item_bin_cnt[1]=$item_bin_cnt[2]=$item_bin_cnt[3]=0;
$db->query("SELECT status_code, count(*) as cnt FROM item 
             WHERE slice_id = '$p_slice_id'
             GROUP BY status_code");
while( $db->next_record() )
  $item_bin_cnt[ $db->f(status_code) ] = $db->f(cnt);

$db->query("SELECT count(*) as cnt FROM item 
             WHERE slice_id = '$p_slice_id'
               AND status_code=1 
               AND expiry_date <= '$now' ");
if( $db->next_record() )
  $item_bin_cnt_exp = $db->f(cnt);

$db->query("SELECT count(*) as cnt FROM item 
             WHERE slice_id = '$p_slice_id'
               AND status_code=1 
               AND publish_date > '$now' ");
if( $db->next_record() )
  $item_bin_cnt_pend = $db->f(cnt);
  
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo L_EDITOR_TITLE ?></title>
<SCRIPT Language="JavaScript"><!--
function SubmitItems(act) {
  document.itemsform.action.value = act
  document.itemsform.submit()
}

function MarkedActionGo() {
  var ms = document.markedform.markedaction;
  switch( ms.options[ms.selectedIndex].value ) {
    case "1-app": SubmitItems('app'); 
                  break;
    case "2-hold": SubmitItems('hold'); 
                  break;
    case "3-trash": SubmitItems('trash'); 
                  break;
    case "4-feed": OpenFeedForm(); 
                  break;
    case "5-view": OpenPreview(); 
                  break;
  }
}

var previewwindow
var feedformwindow

function OpenPreview() {
  var len = document.itemsform.elements.length
  var i=0
  var name = document.itemsform.elements[i].name
  for( i=0; i<len; i++ ) {
    name = document.itemsform.elements[i].name
    if( name.substring(0,3) == 'chb') {  //items checkboxes
      if( document.itemsform.elements[i].checked == true) {
        if ((previewwindow != null) && (!previewwindow.closed)) {
          previewwindow.close()    // in order to preview go on top after open
        }  // extract id from chb[x14451...]  - x is just foo character
        previewwindow = open('<?php echo con_url($r_slice_view_url,"sh_itm=")?>'+name.substring(5,name.indexOf(']')),'fullwindow');
        return;
      }  
    }
  }    
}  

function SelectVis(state) {
  var len = document.itemsform.elements.length
  for( var i=0; i<len; i++ )
    if( document.itemsform.elements[i].name.substring(0,3) == 'chb')  //items checkboxes
      document.itemsform.elements[i].checked = state;    
}

function OpenFeedForm(){
  if( feedformwindow != null ) 
    feedformwindow.close()    // in order to feedform window go on top after open
  feedformwindow = open('<?php echo $sess->url("feed_to.php3")?>','feedform','scrollbars')
}

<?php //called by the f_k alias function (see item.php3) 
echo '
function CallLiveCheckbox (controlName) {
    LiveCheckbox (
        "itemsform", 
        controlName, 
        "'.$AA_INSTAL_PATH.'images/",
        "'.$AA_INSTAL_PATH.'live_checkbox.php3",
        "'._m("on").'",
        "'._m("off").'",
        "'._m("changing to on").'",
        "'._m("changing to off").'",
        "'._m("error: change failed").'",
        "'._m("Please wait until the database change is made and the checkbox changes to ON.").'",
        "'._m("Please wait until the database change is made and the checkbox changes to OFF.").'",
        "'._m("Please wait until the other request is finished.").'");
}'; ?>
  
// -->
</SCRIPT>
<SCRIPT language=javascript src="<?php echo $AA_INSTAL_PATH ?>include/live_checkbox.js">
</SCRIPT>

</head> <?php

require $GLOBALS[AA_INC_PATH]."menu.php3";
showMenu ($aamenus, "itemmanager", "", $navbar != "0", $leftbar != "0");

  # ACTIVE | EXPIRED | PENDING | HOLDING | TRASH | ALL
switch( $r_bin_state ) {
  case "app":   $st_name = "st1";    // name of scroller for approved bin
                $bin_condition = 'ACTIVE';
                $table_icon = "../images/app.gif";
                $table_name = L_ACTIVE_BIN;
                break;
  case "appb":  $st_name = "st1b";    // name of scroller for approved bin - pending
                $bin_condition = 'PENDING';
                $table_icon = "../images/app.gif";
                $table_name = L_ACTIVE_BIN_PENDING;
                break;
  case "appc":  $st_name = "st1c";    // name of scroller for approved bin - expired
                $bin_condition = 'EXPIRED';
                $table_icon = "../images/app.gif";
                $table_name = L_ACTIVE_BIN_EXPIRED;
                break;
  case "hold":  $st_name = "st2";    // name of scroller for holding bin
                $bin_condition = 'HOLDING';
                $table_icon = "../images/hold.gif";
                $table_name = L_HOLDING_BIN;
                break;
  case "trash": $st_name = "st3";    // name of scroller for trash bin
                $bin_condition = 'TRASH';
                $table_icon = "../images/trsh.gif";
                $table_name = L_TRASH_BIN;
                break;
}

$st = $$st_name;   // use right scroller

# create or update scroller for actual bin
if(is_object($st)) {
  $st->updateScr(sess_return_url($PHP_SELF) . "&"); // use $return_url if set.
}else {
  $st = new scroller($st_name, sess_return_url($PHP_SELF) . "&"); // use $return_url if set.
  $st->metapage=($listlen ? $listlen : EDIT_ITEM_COUNT);
  $sess->register($st_name); 
}

if( $listlen )
  $st->metapage = $listlen;

$st->addFilter("slice_id", "md5", $slice_id);

# find item ids to show
if (! $perm_edit_all )
  $conds[]=array( 'operator' => '=',
                  'value' => $auth->auth[uid],
                  'posted_by.......' => 1 );
                  
# if user sets search condition
if( $r_admin_search )
  $conds[]=array( 'operator' => 'LIKE',
                  'value' => $r_admin_search,
                  $r_admin_search_field => 1 );

# set user defined sort order
$sort[] = array ( $r_admin_order => $r_admin_order_dir); 

$item_ids=QueryIDs($fields, $slice_id, $conds, $sort, "", $bin_condition);

$format_strings = array ( "compact_top"=>$slice_info[admin_format_top],
                          "category_sort"=>false,
                          "category_format"=>"",
                          "category_top"=>"",
                          "category_bottom"=>"",
                          "even_odd_differ"=>false,
                          "even_row_format"=>"",
                          "odd_row_format"=>$slice_info[admin_format],
                          "compact_remove"=>$slice_info[admin_remove],
                          "compact_bottom"=>$slice_info[admin_format_bottom],
                          "id"=>$slice_info['id']);

echo "<center>";
echo "$Msg <br>";

# ------- Caption -----------


echo "<table border=0 cellspacing=0 class=login width=460>" .
     "<TR><TD width=24><img src='$table_icon' border=0 alt=''></TD>".
     "<TD align=center class=tablename width=436> $table_name </TD></TR>".
 "</table>";

# echo '<form name="itemsform" method=post action="'. $sess->url($PHP_SELF) .'">'.
// action URL with return_url if $return_url is set.
echo '<form name="itemsform" method=post action="'. $sess->url($PHP_SELF).make_return_url("&return_url=") .'">'.
'<table border="0" cellspacing="0" cellpadding="0" bgcolor="#F5F0E7">';

                         
if( count( $item_ids ) > 0 ) {
  $aliases = GetAliasesFromFields($fields);

  $itemview = new itemview( $db, $format_strings, $fields, $aliases, $item_ids,
              $st->metapage * ($st->current-1), $st->metapage, $r_slice_view_url );
  $itemview->print_view("NOCACHE");   # big security hole is open if we cache it
                                      # (links to itemedit.php3 would stay with 
                                      # session ids in cache - you bacame 
                                      # another user !!!
    
  $st->countPages( count( $item_ids ) );

  echo '</table><br>';

	if($st->pageCount() > 1)
    $st->pnavbar();
}  
else 
  echo "<tr><td><div class=tabtxt>". L_NO_ITEM_FOUND ."</div></td></table>";
  
######## add by setu 2002-0206 #######
### Action for Marked item ###

if ($action_selected != "0")
{  
echo '<input type=hidden name=action value="">';      // filled by javascript function SubmitItem and SendFeed in feed_to.php3
echo '<input type=hidden name=feed2slice value="">';  // array of comma delimeted slices in which feed to - filled by javascript function SendFeed in feed_to.php3 
echo '<input type=hidden name=feed2app value="">';    // array of comma delimeted slices in which we have to feed into approved - filled by javascript function SendFeed in feed_to.php3 
echo '</form></center>';


if( ($r_bin_state != "app")  AND 
    ($r_bin_state != "appb") AND 
    ($r_bin_state != "appc") AND 
    CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ITEMS2ACT))
  $markedaction["1-app"] = L_MOVE_TO_ACTIVE_BIN; 

if( ($r_bin_state != "hold") AND 
    CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ITEMS2HOLD))
  $markedaction["2-hold"] = L_MOVE_TO_HOLDING_BIN;
  
if( ($r_bin_state != "trash") AND 
     CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ITEMS2TRASH))
  $markedaction["3-trash"] = L_MOVE_TO_TRASH_BIN;
if ($feed_selected != "0")
$markedaction["4-feed"] = L_FEED;
if ($view_selected != "0")
$markedaction["5-view"] = L_VIEW_FULLTEXT;
  
  // click "go" does not use markedform, it uses itemsfrom above...
  // maybe this action is not used.
echo "<center>
      <form name=markedform method=post action=\"". $sess->url($PHP_SELF).
	make_return_url("&return_url=").			// added by setu, 
	"\">
      <table border=0 cellspacing=0 class=login width=460>
      <TR><TD align=center class=tablename>".
      L_CHANGE_MARKED ." &nbsp; <select name=markedaction>";

reset($markedaction);
while(list($k, $v) = each($markedaction)) 
  echo "<option value=\"". htmlspecialchars($k)."\"> ".
           htmlspecialchars($v) ." </option>";
echo "</select></td><td align='right' class=tablename>
      <a href=\"javascript:MarkedActionGo()\" class=leftmenuy>".L_GO.
      "</a> </TD></TR>".
 "</table></form>";
}
###############

######## add by setu 2002-0206 #######
# user definend sorting and filtering ---------------------------------------
if ($sort_filter != "0") {
  # echo '<form name=filterform method=post action="'. $sess->url($PHP_SELF). '">
  #if ($debug)  echo "sess_return_url=".sess_return_url($PHP_SELF)."<br>";
  // action URL with return_url if $return_url is set.
  echo '<form name=filterform method=post action="'. $sess->url($PHP_SELF).make_return_url("&return_url="). '">
      <table width="460" border="0" cellspacing="0" cellpadding="0" 
      class=leftmenu bgcolor="'. COLOR_TABBG .'">';

reset( $fields );
while( list ($k, $v ) = each( $fields ) ) {
  $lookup_fields[$k] = $v[name];
  if( $v[text_stored] )
    $lookup_text_fields[$k] = $v[name];
}
    
  #order
echo "<tr>
       <td class=leftmenuy><b>". L_ORDER ."</b></td>
       <td class=leftmenuy>";
FrmSelectEasy('admin_order', $lookup_fields, $r_admin_order);
echo "<input type='checkbox' name='admin_order_dir'". 
     ( ($r_admin_order_dir=='d') ? " checked> " : "> " ) . L_DESCENDING. "</td>
     <td rowspan=2 align='right' valign='middle'><a
      href=\"javascript:document.filterform.submit()\" class=leftmenuy>". L_GO ."</a> </td></tr>";

  # filter
echo "<tr><td class=leftmenuy><b>". L_SEARCH ."</b></td>
     <td>";
FrmSelectEasy('admin_search_field', $lookup_text_fields, $r_admin_search_field);
echo "<input type='Text' name='admin_search' size=20
      maxlength=254 value=\"". safe($r_admin_search). "\"></td></tr></table>
      <input type=hidden name=action value='filter'></form></center>";
echo "<p></p>"; // workaround for align=left bug
}

HtmlPageEnd(); 

  $$st_name = $st;   // to save the right scroller 
  page_close();
?>
