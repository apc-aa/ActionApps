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

require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"] . "varset.php3";
require_once $GLOBALS["AA_INC_PATH"] . "view.php3";
require_once $GLOBALS["AA_INC_PATH"] . "pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"] . "item.php3";
require_once $GLOBALS["AA_INC_PATH"] . "feeding.php3";
require_once $GLOBALS["AA_INC_PATH"] . "itemfunc.php3";
require_once $GLOBALS["AA_INC_PATH"] . "notify.php3";
require_once $GLOBALS["AA_INC_PATH"] . "searchlib.php3";
require_once $GLOBALS["AA_INC_PATH"] . "formutil.php3";
require_once $GLOBALS["AA_INC_PATH"] . "sliceobj.php3";
require_once $GLOBALS["AA_INC_PATH"]."msgpage.php3";

FetchSliceReadingPassword();
$now = now();

function MoveItems($chb,$status) {
  global $db, $auth, $slice_id;
  if( isset($chb) AND is_array($chb) ) {
    $item_ids = "";
    # If moving a LOT of items, then extend time limit
    if (count($chb) > 100 ) set_time_limit(240);
    reset( $chb );
    while( list($it_id) = each( $chb ) )
        $item_ids[] = pack_id(substr($it_id,1));

    if ($item_ids && Event_ItemsBeforeMove( $item_ids, $slice_id, $status )) {
        $SQL = "UPDATE item SET
           status_code = $status,
           last_edit   = '$now',
           edited_by   = '". quote(isset($auth) ? $auth->auth["uid"] : "9999999999")."'";

        // E-mail Alerts
        $moved2active = $status == 1 ? time() : 0;
        $SQL .= ", moved2active = $moved2active";

        $SQL .= " WHERE id IN ('".join_and_quote("','",$item_ids)."')";
        $db->tquery ($SQL);
        Event_ItemsAfterMove( $item_ids, $slice_id, $status );
    }
                                         // substr removes first 'x'
    $GLOBALS[pagecache]->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
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

$perm_edit_all  = IfSlPerm(PS_EDIT_ALL_ITEMS);
$perm_edit_self = IfSlPerm(PS_EDIT_SELF_ITEMS);

if( !$perm_edit_all && !$perm_edit_self) {
  MsgPage($sess->url(self_base())."index.php3", _m("You do not have permission to edit items in the slice:").sliceid2name($slice_id));
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

$sess->register("navbar");
$sess->register("leftbar");
$sess->register("sort_filter");
$sess->register("action_selected");
$sess->register("feed_selected");
$sess->register("view_selected");
$sess->register("bodyonly");

if ($bodyonly == "1") {
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

function ProovePerm( $perm, $msg ) {
    if(!IfSlPerm($perm)) {
        MsgPageMenu($sess->url(self_base())."index.php3", $msg, "items");
        exit;
    }
}

if ($akce) {
  switch( $akce ) {  // script post parameter
    case "app":
      ProovePerm( PS_ITEMS2ACT, _m("You have not permissions to move items"));
      MoveItems($chb,1);
      FeedAllItems($chb, $fields);    // Feed all checked items
      break;
    case "hold":
      ProovePerm( PS_ITEMS2HOLD, _m("You have not permissions to move items"));
      MoveItems($chb,2);
      break;
    case "trash":
      ProovePerm( PS_ITEMS2TRASH, _m("You have not permissions to move items"));
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
    case "email":
      if (! $called_from_wizard) {
          ShowWizardFrames (
              $sess->url($AA_INSTAL_PATH."admin/tabledit.php3?set_tview=email"),
              $sess->url($AA_INSTAL_PATH."admin/wizard_email.php3?step=2"),
              _m("Send Emails Wizard"));
          exit;
      }
      break;
  }
  // If we are just doing stuff for the action, then should go to return_url if present
  // rather than staying here on index.php3
  go_return_or_url("",0,0); // Note this exits if return_url present

} // end if ($akce)

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

// empty trash:
if($Delete == "trash") {
    // feeded items we can easy delete
    ProovePerm( PS_DELETE_ITEMS, _m("You have not permissions to remove items"));
    $db->query("SELECT id FROM item
               WHERE status_code=3 AND slice_id = '$p_slice_id'");
    $items_to_delete = "";
    while( $db->next_record() )
        $items_to_delete[] = $db->f("id");
    if (Event_ItemsBeforeDelete( $items_to_delete, $slice_id )) {
        // delete content of all fields
        // don't worry about fed fields - content is copied
        $wherein = "IN ('".join_and_quote("','", $items_to_delete)."')";
        $db->query("DELETE FROM content WHERE item_id ".$wherein);
        $db->query("DELETE FROM item WHERE id ".$wherein);

        $GLOBALS[pagecache]->invalidateFor("slice_id=$slice_id");
   }
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
<title><?php echo _m("Editor window - item manager") ?></title>
<SCRIPT Language="JavaScript"><!--
    function SubmitItems(act) {
      document.itemsform.akce.value = act
      document.itemsform.submit()
    }

    function MarkedActionGo() {
      var ms = document.itemsform.markedaction_select;
      switch( ms.options[ms.selectedIndex].value ) {
        case "1-app":   SubmitItems('app'); break;
        case "2-hold":  SubmitItems('hold'); break;
        case "3-trash": SubmitItems('trash'); break;
        case "4-feed":  OpenFeedForm(); break;
        case "5-view":  OpenPreview(); break;
        case "6-email": SubmitItems('email'); break;
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
      state = 2
      for( var i=0; i<len; i++ )
        if( document.itemsform.elements[i].name.substring(0,3) == 'chb') { //items checkboxes
          if (state == 2) {
            state = ! document.itemsform.elements[i].checked;
          }
          document.itemsform.elements[i].checked = state;
        }
    }

    function OpenFeedForm(){
      if( feedformwindow != null )
        feedformwindow.close()    // in order to feedform window go on top after open
      feedformwindow = open('<?php echo $sess->url("feed_to.php3")?>','feedform','scrollbars')
    }

    //called by the f_k alias function (see item.php3)
    function CallLiveCheckbox (controlName) {
        myimg = document.itemsform[controlName];
        myimg.src = "<?php echo $AA_INSTAL_PATH ?>images/cb_2off.gif";

        imgsrc = "<?php echo $sess->url ($AA_INSTAL_PATH."live_checkbox.php3")
            ?>&"+controlName+"=1&no_cache="+Math.random();
        setTimeout("ChangeImgSrc ('"+controlName+"','"+imgsrc+"')", 1);
    }

    function ChangeImgSrc (imageName, newsrc) {
        document.itemsform[imageName].src = newsrc;
    }

// -->
</SCRIPT>

</head> <?php

require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
showMenu ($aamenus, "itemmanager", $r_bin_state, $navbar != "0", $leftbar != "0");

  # ACTIVE | EXPIRED | PENDING | HOLDING | TRASH | ALL
switch( $r_bin_state ) {
  case "app":   $st_name = "st1";    // name of scroller for approved bin
                $bin_condition = 'ACTIVE';
                $table_icon = "../images/app.gif";
                $table_name = _m("Active");
                break;
  case "appb":  $st_name = "st1b";    // name of scroller for approved bin - pending
                $bin_condition = 'PENDING';
                $table_icon = "../images/app.gif";
                $table_name = _m("Pending");
                break;
  case "appc":  $st_name = "st1c";    // name of scroller for approved bin - expired
                $bin_condition = 'EXPIRED';
                $table_icon = "../images/app.gif";
                $table_name = _m("Expired");
                break;
  case "hold":  $st_name = "st2";    // name of scroller for holding bin
                $bin_condition = 'HOLDING';
                $table_icon = "../images/hold.gif";
                $table_name = _m("Hold bin");
                break;
  case "trash": $st_name = "st3";    // name of scroller for trash bin
                $bin_condition = 'TRASH';
                $table_icon = "../images/trsh.gif";
                $table_name = _m("Trash bin");
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

// It looks like this duplicates the line above? (Mitra)
// No - the line above is applied just if $st is not an object
if( $listlen ) {
  $st->metapage = $listlen;
  $st->current  = 1;
  if ($listlen > 500) set_time_limit(240);
}

$st->addFilter("slice_id", "md5", $slice_id);

# find item ids to show
if (! $perm_edit_all )
  $conds[]=array( 'operator' => '=',
                  'value' => $auth->auth[uid],
                  'posted_by.......' => 1 );

# if user sets search condition
if( $r_admin_search != "" )
  $conds[]=array( 'operator' => 'LIKE',
                  'value' => $r_admin_search,
                  $r_admin_search_field => 1 );

# set user defined sort order
$sort[] = array ( $r_admin_order => $r_admin_order_dir);

$zids=QueryZIDs($fields, $slice_id, $conds, $sort, "", $bin_condition);

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

# user defined sorting and filtering
if ($sort_filter != "0") {
      # echo '<form name=filterform method=post action="'. $sess->url($PHP_SELF). '">
      #if ($debug)  echo "sess_return_url=".sess_return_url($PHP_SELF)."<br>";
      // action URL with return_url if $return_url is set.
    echo '<form name=filterform method=post action="'. $sess->url($PHP_SELF).make_return_url("&return_url="). '">
          <input type="hidden" name="akce" value="filter">
          <table border="0" cellspacing="3" cellpadding="0"
          class=leftmenu bgcolor="'. COLOR_TABBG .'">';

    reset( $fields );
    while( list ($k, $v ) = each( $fields ) ) {
      $lookup_fields[$k] = $v[name];
      if( $v[text_stored] )
        $lookup_text_fields[$k] = $v[name];
    }

    $searchimage = "<a href='javascript:document.filterform.submit()'>"
    ."<img src='../images/search.gif' alt='"._m("Search")."' border=0></a>";

      # filter
    echo "<tr><td class=search>&nbsp;".$searchimage."&nbsp;&nbsp;<b>"
        . _m("Search") ."</b>&nbsp;</td><td>";

    echo "<input type='Text' name='admin_search' size=20
          maxlength=254 value=\"". safe($r_admin_search). "\">"
         ."&nbsp;<b>"._m("in")."</b>&nbsp;";
    FrmSelectEasy('admin_search_field', $lookup_text_fields, $r_admin_search_field);
    echo $searchimage."&nbsp;</td></tr>";
    echo "<input type=hidden name=action value='filter'>";

      #order
    echo "<tr><td class=search align=left>&nbsp;"
    ."<a href='javascript:document.filterform.submit()'>"
    ."<img src='../images/order.gif' alt='"._m("Order")."' border=0></a>&nbsp;&nbsp;<b>"
        . _m("Order") ."</b></td><td class=leftmenuy align=left>";
    FrmSelectEasy('admin_order', $lookup_fields, $r_admin_order, "onchange='document.filterform.submit()'");
    echo "<input type='checkbox' name='admin_order_dir' onchange='document.filterform.submit()'".
         ( ($r_admin_order_dir=='d') ? " checked> " : "> " ) . _m("Descending"). "</td></tr>";

    echo "</table></form></center><p></p>"; // workaround for align=left bug
}

# ------- Caption -----------

/*
echo "<table border=0 cellspacing=0 class=login width=460>" .
     "<TR><TD width=24><img src='$table_icon' border=0 alt=''></TD>".
     "<TD align=center class=tablename width=436> $table_name </TD></TR>".
 "</table>";
*/
# echo '<form name="itemsform" method=post action="'. $sess->url($PHP_SELF) .'">'.
// action URL with return_url if $return_url is set.
echo '<form name="itemsform" method=post action="'. $sess->url($PHP_SELF).make_return_url("&return_url=") .'">'.
'<table border="0" cellspacing="0" cellpadding="1" bgcolor="#F5F0E7" class="mgr_table">';


if( $zids->count() == 0 ) {
    echo "<tr><td><div class=tabtxt>". _m("No item found") ."</div></td></tr></table>";
    HtmlPageEnd();
    page_close();
    exit;
}

$aliases = GetAliasesFromFields($fields);

// Added by Jakub on 6.3.2003, not sure if OK:
echo "<tr><td class=tabtxt>";
$itemview = new itemview($format_strings, $fields, $aliases, $zids,
          $st->metapage * ($st->current-1), $st->metapage, $r_slice_view_url );
$itemview->print_view("NOCACHE");   # big security hole is open if we cache it
                                  # (links to itemedit.php3 would stay with
                                  # session ids in cache - you bacame
                                  # another user !!!
// Added by Jakub on 6.3.2003, not sure if OK:
echo "</td></tr>";

$st->countPages( $zids->count() );

if($st->pageCount() > 1 || $action_selected != "0") {
    echo "<tr><td colspan=100 class=tabtxt>
        <table border=0 cellpadding=3><tr><td class=tabtxt>";
}

if ($action_selected != "0")
{
    echo '<input type=hidden name=akce value="">';      // filled by javascript function SubmitItem and SendFeed in feed_to.php3
    echo '<input type=hidden name=feed2slice value="">';  // array of comma delimeted slices in which feed to - filled by javascript function SendFeed in feed_to.php3
    echo '<input type=hidden name=feed2app value="">';    // array of comma delimeted slices in which we have to feed into approved - filled by javascript function SendFeed in feed_to.php3

    if( ($r_bin_state != "app")  AND
        ($r_bin_state != "appb") AND
        ($r_bin_state != "appc") AND
        IfSlPerm(PS_ITEMS2ACT))
      $markedaction["1-app"] = _m("Move to Active");

    if( ($r_bin_state != "hold") AND
        IfSlPerm(PS_ITEMS2HOLD))
      $markedaction["2-hold"] = _m("Move to Holding bin");

    if( ($r_bin_state != "trash") AND
         IfSlPerm(PS_ITEMS2TRASH))
      $markedaction["3-trash"] = _m("Move to Trash");
    if ($feed_selected != "0")
        $markedaction["4-feed"] = _m("Export");
    if ($view_selected != "0")
        $markedaction["5-view"] = _m("Preview");
    if ($slice_info["type"] == "ReaderManagement")
        $markedaction["6-email"] = _m("Send email wizard");

    if (is_array ($markedaction) && count ($markedaction)) {
        echo "<img src='".$AA_INSTAL_PATH."images/arrow_ltr.gif'>
            <a href='javascript:SelectVis()'>"._m("Select all")."</a>&nbsp;&nbsp;&nbsp;&nbsp;";

          // click "go" does not use markedform, it uses itemsfrom above...
          // maybe this action is not used.
        echo "<select name='markedaction_select'>
              <option value=\"nothing\">"._m("Selected items").":";

        reset($markedaction);
        while(list($k, $v) = each($markedaction))
          echo "<option value=\"". htmlspecialchars($k)."\"> ".
                   htmlspecialchars($v);
        echo "</select>&nbsp;&nbsp;<a href=\"javascript:MarkedActionGo()\" class=leftmenuy>"._m("Go")."</a>";
    }
}

if($st->pageCount() > 1 || $action_selected != "0") {
    if ($st->pageCount() > 1) {
        echo "</td></tr><tr height=3><td></td></tr>
            <tr><td class=tabtxt><b>"._m("Items Page").":&nbsp;&nbsp;";
        $st->pnavbar();
        echo "</b>";
    }
    echo "</td></tr></table></td></tr>";
}

echo '</table></form><br>';

HtmlPageEnd();

  $$st_name = $st;   // to save the right scroller
  page_close();
?>
