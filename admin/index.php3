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
require $GLOBALS[AA_INC_PATH] . "searchlib.php3";

function MoveItems($chb,$status) {
  global $db;
  if( isset($chb) AND is_array($chb) ) {
    reset( $chb );
    while( list($it_id,) = each( $chb ) )
      $db->query("UPDATE item SET status_code = $status 
                   WHERE id='".q_pack_id(substr($it_id,1))."'"); 
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

#prints icon1 or icon2 depending on cond
function SelectIconIf( $cond, $icon1, $alt1, $icon2, $alt2 ) {
  if( !$cond ) {
    $icon1=$icon2; 
    $alt1=$alt2;
  }  
  echo '<td><img src="'. $icon1 .'" width=24 border=0 alt="'. $alt1 .'"></td>'; 
}

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

$slice_info = GetSliceInfo($p_slice_id);

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
  list($fields,) = GetSliceFields($p_slice_id);

switch( $action ) {  // script post parameter 
  case "app":
    if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ITEMS2ACT)) {
      MsgPage($sess->url(self_base())."index.php3", L_NO_PS_MOVE_ITEMS);
      exit;
    }  
    MoveItems($chb,1);
    FeedAllItems($chb, $fields);    // Feed all checked items
    break;
  case "hold":
    if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ITEMS2HOLD)) {
      MsgPage($sess->url(self_base())."index.php3", L_NO_PS_MOVE_ITEMS);
      exit;
    }  
    MoveItems($chb,2);
    break;
  case "trash":
    if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ITEMS2TRASH)) {
      MsgPage($sess->url(self_base())."index.php3", L_NO_PS_MOVE_ITEMS);
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
//          huh("Item: $it_id -> $sl_id <br>/n");
            $it_id = substr($it_id,1);  // remove beginning 'x'
            FeedItemTo($it_id, $sl_id, $fields, ($approvedfeed[$sl_id] ? 'y':'n'), 0);
          }  
        }
      }
    }        
    break;
}

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
    MsgPage($sess->url(self_base())."index.php3", L_NO_DELETE_ITEMS);
    exit;
  }  
 	$db->query("SELECT id FROM item 
               WHERE status_code=3 AND slice_id = '$p_slice_id'");
    # delete content of all fields
  while( $db->next_record() ) {   
    $db2->query("DELETE FROM content
                    WHERE item_id = '". $db->f(id) ."'");  # don't worry about
  }                                           # fed fields - content is copied
    # delete content of item fields
 	$db->query("DELETE FROM item 
             WHERE status_code=3 AND slice_id = '$p_slice_id'");

  $cache = new PageCache($db, CACHE_TTL, CACHE_PURGE_FREQ); # database changed - 
  $cache->invalidateFor("slice_id=$slice_id");  # - invalidate old cached values
}

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
        }
        previewwindow = open('<?php echo con_url($r_slice_view_url,"sh_itm=")?>'+name.substring(4,name.indexOf(']')),'fullwindow');
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

// -->
</SCRIPT>
</head> <?php

if( $open_preview )
  echo "<body OnLoad=\"OpenPreview('$open_preview')\">";
 else 
  echo '<body>';

$editor_page = true;
require $GLOBALS[AA_INC_PATH] . "navbar.php3";
require $GLOBALS[AA_INC_PATH] . "leftbar.php3";

$de = getdate(time());

switch( $r_bin_state ) {
  case "app":   $st_name = "st1";    // name of scroller for approved bin
                if( $apple_design )   // apple_design is old admin interface design with three bins Approved, Holding bin and Trash, where expired and pending items was shown by apples.
                  $conditions['status_code.....'] = 1;
                 else {
                  $item_cond="";   # it means the same as on webpage (see searchlib)
                                   # = "(status_code = 1) AND (publish_date <= '". mktime(23,59,59,$de[mon],$de[mday],$de[year]) ."') AND (expiry_date > '". mktime(0,0,0,$de[mon],$de[mday],$de[year]). "')";
                }                   
                $table_icon = "../images/app.gif";
                $table_name = L_ACTIVE_BIN;
                break;
  case "appb":  $st_name = "st1b";    // name of scroller for approved bin - pending
                $item_cond = "(status_code = 1) AND ".
                             "(publish_date > '". mktime(23,59,59,$de[mon],$de[mday],$de[year]) ."') ";
                $table_icon = "../images/app.gif";
                $table_name = L_ACTIVE_BIN_PENDING;
                break;
  case "appc":  $st_name = "st1c";    // name of scroller for approved bin - expired
                $item_cond = "(status_code = 1) AND ".
                             "(expiry_date <= '". mktime(0,0,0,$de[mon],$de[mday],$de[year]). "')";
                $table_icon = "../images/app.gif";
                $table_name = L_ACTIVE_BIN_EXPIRED;
                break;
  case "hold":  $st_name = "st2";    // name of scroller for holding bin
                $item_cond = "status_code = 2";
                $table_icon = "../images/hold.gif";
                $table_name = L_HOLDING_BIN;
                break;
  case "trash": $st_name = "st3";    // name of scroller for trash bin
                $item_cond = "status_code = 3";
                $table_icon = "../images/trsh.gif";
                $table_name = L_TRASH_BIN;
                break;
}

$st = $$st_name;   // use right scroller

# create or update scroller for actual bin
if(is_object($st)) {
  $st->updateScr($sess->url($PHP_SELF) . "&");
}else {
  $st = new scroller($st_name, $sess->url($PHP_SELF) . "&");	
  $st->metapage=EDIT_ITEM_COUNT;

/*
  reset($AF_COLUMNS);   // set filters for all possibly displayed fields
  while( list($afname, $afarr) = each($AF_COLUMNS) ) {
    if( $afarr["field"] ) {
      if( $afname != $afarr["field"] )
       	$st->addFilter($afname, $afarr["type"], "", $afarr["field"]);
       else 
       	$st->addFilter($afname, $afarr["type"]);
    }  
  }    
*/
  
  $sess->register($st_name); 
}

$st->addFilter("slice_id", "md5", $slice_id);

//  where (($bin_condition) AND fulltexts.ft_id=items.master_id AND created_by='".$auth->auth[uid]."' AND (". $st->sqlCondFilter().")) ");

//huh ( "PSlice:$p_slice_id");
$conditions['slice_id........'] = $p_slice_id;
if (! $perm_edit_all )
  $conditions['posted_by.......'] = $auth->auth[uid];
  
$item_ids = GetItemAppIds($fields, $db, $p_slice_id, 
                            $conditions, "DESC", "", "",$item_cond);

$format_strings = array ( "compact_top"=>$slice_info[admin_format_top],
                          "category_sort"=>false,
                          "category_format"=>"",
                          "category_top"=>"",
                          "category_bottom"=>"",
                          "even_odd_differ"=>false,
                          "even_row_format"=>"",
                          "odd_row_format"=>$slice_info[admin_format],
                          "compact_remove"=>$slice_info[admin_remove],
                          "compact_bottom"=>$slice_info[admin_format_bottom]);

echo "<center>";
echo "$Msg <br>";

# ------- Caption -----------


echo "<table border=0 cellspacing=0 class=login width=460>" .
     "<TR><TD width=24><img src='$table_icon' border=0 alt=''></TD>".
     "<TD align=center class=tablename width=436> $table_name </TD></TR>".
 "</table>";

echo '<form name="itemsform" enctype="multipart/form-data" method=post action="'. $sess->url($PHP_SELF) .'">'.
'<table width="460" border="0" cellspacing="0" cellpadding="0" bgcolor="#F5F0E7">';

                         
if( count( $item_ids ) > 0 ) {
  $aliases = GetAliasesFromFields($fields);

//p_arr_m($aliases);
//p_arr_m($format_strings);

  $itemview = new itemview( $db, $format_strings, $fields, $aliases, $item_ids,
              $st->metapage * ($st->current-1), $st->metapage, $r_slice_view_url );
  $itemview->print_view();
    
  $st->countPages( count( $item_ids ) );

  echo '</table><br>';
  
	if($st->pageCount() > 1)
    $st->pnavbar();
}  
else 
  echo "<tr><td><div class=tabtxt>". L_NO_ITEM ."</div></td></td></table>";
  
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

$markedaction["4-feed"] = L_FEED;
$markedaction["5-view"] = L_VIEW_FULLTEXT;
  
echo "<center>
      <form name=markedform method=post action=\"". $sess->url($PHP_SELF)."\">
      <table border=0 cellspacing=0 class=login width=460>
      <TR><TD align=center class=tablename width=436>".
      L_CHANGE_MARKED ." &nbsp; <select name=markedaction>";

reset($markedaction);
while(list($k, $v) = each($markedaction)) 
  echo "<option value=\"". htmlspecialchars($k)."\"> ".
           htmlspecialchars($v) ." </option>";
echo "</select>
      <a href=\"javascript:MarkedActionGo()\" class=leftmenuy>".L_GO.
      "</a></TD></TR>".
 "</table></form></center>";

echo L_ICON_LEGEND;
echo L_SLICE_HINT;

$ssiuri = ereg_replace("/admin/.*", "/slice.php3", $PHP_SELF);

echo "<br><pre>&lt;!--#include virtual=&quot;" . $ssiuri . 
     "?slice_id=" . $slice_id . "&quot;--&gt;</pre>

  </body>
</html>";

  $$st_name = $st;   // to save the right scroller 
  page_close();

/*

$Log$
Revision 1.18  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.17  2001/02/26 17:26:08  honzam
color profiles

Revision 1.16  2001/02/26 12:22:30  madebeer
moved hint on .shtml to slicedit
changed default item manager design

Revision 1.15  2001/02/25 08:33:40  madebeer
fixed some table formats, cleaned up admin headlines

Revision 1.14  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.12  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.8  2000/08/17 15:14:32  honzam
new possibility to redirect item displaying (for database changes see CHANGES)

Revision 1.7  2000/08/07 15:27:45  kzajicek
Added missing semicolon in global statement

Revision 1.6  2000/08/03 12:38:01  honzam
HTML formated pictures for admin interface added

Revision 1.5  2000/07/25 13:23:36  kzajicek
Fixed small inaccuracy in OpenPreview (Netscape only).

Revision 1.4  2000/07/12 14:26:40  kzajicek
Poor printing of the SSI statement fixed

Revision 1.3  2000/07/07 21:28:17  honzam
Both manual and automatical feeding bug fixed

Revision 1.1.1.1  2000/06/21 18:39:55  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:46  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.17  2000/06/12 19:58:23  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.16  2000/06/09 15:14:09  honzama
New configurable admin interface

Revision 1.15  2000/04/24 16:43:41  honzama
Small interface changes.

Revision 1.14  2000/03/29 14:30:47  honzama
New direct feeding (Export). Icon legend updated.

Revision 1.13  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/
?>
