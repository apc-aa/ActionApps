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
require $GLOBALS[AA_INC_PATH] . "item.php3";
require $GLOBALS[AA_INC_PATH] . "feeding.php3";
                     
//huh("-=-$action==");
function MoveItems($chb,$status) {
  global $db;
//p_arr_m($chb);
//huh($status);
  if( isset($chb) AND is_array($chb) ) {
    reset( $chb );
    while( list($it_id,) = each( $chb ) )
      $db->query("update items set status_code = $status where id='".q_pack_id($it_id)."'");
  }
}  

function FeedAllItems($chb) {    // Feed all checked items
  global $db;
  if( isset($chb) AND is_array($chb) ) {
    reset( $chb );
    while( list($it_id,) = each( $chb ) )
      FeedItem( $it_id, $db );
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

function get_cat_names() {
  global $db,$p_slice_id
  $SQL= " SELECT name, id FROM categories, catbinds WHERE categories.id = catbinds.category_id AND catbinds.slice_id='".$p_slice_id."'";
  $db->query($SQL);
  while ($db->next_record()){
    $arr[$db->f("name")]=unpack_id($db->f("id"));  
  }
  return $arr;  
} 

switch( $action ) {  // script post parameter 
  case "app":
    if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ITEMS2ACT)) {
      MsgPage($sess->url(self_base())."index.php3", L_NO_PS_MOVE_ITEMS);
      exit;
    }  
    MoveItems($chb,1);
    FeedAllItems($chb, $db);    // Feed all checked items
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
      go_url(con_url($sess->url("itemedit.php3"),"encap=false&edit=1&id=") .key($chb) );
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
            FeedItemTo($it_id, $sl_id, $approvedfeed[$sl_id] , $db);
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

 	$db->query("DELETE FROM items WHERE id <> master_id AND   
                                      status_code=3 AND
                                      slice_id = '$p_slice_id'");

    // base items we can delete only if there is no feeding for this item
    // I have problem with query like (SELECT a.id, b.id from items as a left join items as b on a.id=b.master_id), 
    //    so I can't do it by simple one DELETE query
    
 	$db->query("SELECT DISTINCT id FROM items WHERE status_code=3 AND slice_id = '$p_slice_id'");
  while( $db->next_record() )
    $delete_base[unpack_id($db->f(id))]='y';

  if( is_array($delete_base) ) { 
    reset($delete_base);
    while( list($id,)=each($delete_base) ) {
      $p_id = q_pack_id($id);
      $SQL="SELECT id FROM items WHERE master_id<>id AND master_id ='$p_id'";
      $db->query($SQL);
      if( !($db->next_record()) ) {
        $db->query("DELETE FROM items WHERE id ='$p_id'");
        $db->query("DELETE FROM fulltexts WHERE ft_id ='$p_id'");
      } else
        $Msg = MsgErr(L_ERR_FEEDED_ITEMS);
    }   
  }  
}


HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo L_EDITOR_TITLE ?></title>
<SCRIPT Language="JavaScript"><!--
function SubmitItems(act) {
  document.itemsform.action.value = act
  document.itemsform.submit()
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
        if( previewwindow != null ) 
          previewwindow.close()    // in order to preview go on top after open
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

$categories = get_cat_names();
$category_defined = isset($categories);

switch( $r_bin_state ) {
  case "app":   $st_name = "st1";    // name of scroller for approved bin
                if( $apple_design )   // apple_design is old admin interface design with three bins Approved, Holding bin and Trash, where expired and pending items was shown by apples.
                  $bin_condition = "status_code = 1";
                 else 
                  $bin_condition = "(status_code = 1) AND ".
                                   "(publish_date <= '". date("Y-m-d"). " 23:59:59') AND ".
                                   "(expiry_date > '". date("Y-m-d"). "')";
                $table_icon = "../images/app.gif";
                $table_name = L_ACTIVE_BIN;
                break;
  case "appb":  $st_name = "st1b";    // name of scroller for approved bin - pending
                $bin_condition = "(status_code = 1) AND ".
                                 "(publish_date > '". date("Y-m-d"). " 23:59:59') ";
                $table_icon = "../images/app.gif";
                $table_name = L_ACTIVE_BIN_PENDING;
                break;
  case "appc":  $st_name = "st1c";    // name of scroller for approved bin - expired
                $bin_condition = "(status_code = 1) AND ".
                                 "(expiry_date <= '". date("Y-m-d"). "')";
                $table_icon = "../images/app.gif";
                $table_name = L_ACTIVE_BIN_EXPIRED;
                break;
  case "hold":  $st_name = "st2";    // name of scroller for holding bin
                $bin_condition = "status_code = 2";
                $table_icon = "../images/hold.gif";
                $table_name = L_HOLDING_BIN;
                break;
  case "trash": $st_name = "st3";    // name of scroller for trash bin
                $bin_condition = "status_code = 3";
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

  reset($af_columns);   // set filters for all possibly displayed fields
  while( list($afname, $afarr) = each($af_columns) ) {
    if( $afarr["field"] ) {
      if( $afname != $afarr["field"] )
       	$st->addFilter($afname, $afarr["type"], "", $afarr["field"]);
       else 
       	$st->addFilter($afname, $afarr["type"]);
    }  
  }    
  $sess->register($st_name); 
}

$st->addFilter("slice_id", "md5", $slice_id);

$from = ( ( $perm_edit_all ) ?  
  "items, fulltexts left join categories on items.category_id=categories.id 
     where (($bin_condition) AND fulltexts.ft_id=items.master_id AND (". $st->sqlCondFilter().")) " :
  "items, fulltexts left join categories on items.category_id=categories.id 
     where (($bin_condition) AND fulltexts.ft_id=items.master_id AND created_by='".$auth->auth[uid]."' AND (". $st->sqlCondFilter().")) ");

$db->query("select count(*) as cnt from $from");
$db->next_record();
$st->countPages($db->f(cnt));

// add order clausule
$SQL = 'SELECT items.*, fulltexts.full_text, categories.name as catname FROM '. $from . $st->sortSql();

if( OPTIMIZE_FOR_MYSQL )       // if no mySQL - go to item no (mySQL use LIMIT)
  $SQL .= " LIMIT ". $st->metapage * ($st->current - 1). ", ". $st->metapage;

$db->query($SQL);
$db2 = new DB_AA; 	 // open BD	(for subqueries "feeded into" in long format)

?>

<center>
<?php
                     
echo "$Msg <br>";

$col_count = count($r_slice_config["admin_fields"]);

  // Table -----------------------------
  
echo '<form name="itemsform" enctype="multipart/form-data" method=post action="'. $sess->url($PHP_SELF) .'">';
echo '<table width="460" border="0" cellspacing="0" cellpadding="0" bgcolor="#F5F0E7">
        <TR>';


//if( $category_defined) ----hide category column

  // Table spacing ---------------------
$col_width = 0;
reset($r_slice_config["admin_fields"]); 
while( list($afname, $afval) = each($r_slice_config["admin_fields"]) ) {
  echo '<TD><IMG src="../images/spacer.gif" width="' . $afval["width"] .'" height="1"></TD>';
  $col_width += $afval["width"];
}  
  
echo '</TR>';

  // Table head ------------------------
echo '  <tr><td colspan='. $col_count .'>
           <table width="460" border="0" cellspacing="0" cellpadding="0" bgcolor="#F5F0E7">
             <tr><td class=tablename valign=middle><img src="'.$table_icon.'" border=0 alt="'.$table_name.'"></td>
                 <td><h1 class=tablename>'. $table_name .'</h1></td>
                 <td><h1 class=tablename align=right>('. $st->itmcnt .')</h1></td>
             </tr>
           </table></td>
        </tr>
        <tr><td colspan='.$col_count.'><img src="../images/black.gif" width="'. $col_width .'" height=1></td></tr>
        <tr>';

reset($r_slice_config["admin_fields"]); 
while( list($afname,) = each($r_slice_config["admin_fields"]) ) {
  if( $af_columns[$afname]["field"] ) {
    echo '<td class=scrhead align=center>';
    $st->pSort($af_columns[$afname]["field"], $af_columns[$afname]["title"]);
    echo '</td>';
  }
  else  
   echo '<td class=scrhead align=center>'. $af_columns[$afname]["title"] .'</td>';
}   


echo '</tr><tr><td colspan='.$col_count.'><img src="../images/black.gif" width="'. $col_width .'" height=1></td></tr>';

// Items      ------------------------

$CurItem = new item("",false,1,"", "", "", "", "", EDITOR_GRAB_LEN,"", "");
     // we use item just for accessing f_*() item functions

if( !OPTIMIZE_FOR_MYSQL )          // if no mySQL - go to item no (mySQL use LIMIT)
  if( $db->nf() > 0 )
    @$db->seek(max(0,$st->metapage * ($st->current - 1)));
    
$i=0;   // item counter
while($db->next_record()){ 
  $CurItem->columns = $db->Record; # active row 

  echo '<tr>';
  reset($r_slice_config["admin_fields"]); 
  while( list($afname, ) = each($r_slice_config["admin_fields"]) ) {
    switch($afname) {
      case "id":
      case "master_id":
      case "category_id":
        echo '<td class=iid>'. $CurItem->f_n($afname) .'</td>';
        break;
      case "headline":
        echo '<td><a href="'. con_url($sess->url("itemedit.php3"),"encap=false&edit=1&id=") .$CurItem->f_n("id") .'" class=iheadline>'. $CurItem->f_h("headline"). '</a></td>';
        break;
      case "post_date":
      case "publish_date":
      case "expiry_date":
      case "last_edit":
        echo '<td class=ipostdate>'. $CurItem->f_d($afname) .'</td>';
        break;
      case "abstract":
        echo '<td class=ipostdate>'. $CurItem->f_a($afname) .'</td>';
        break;
      case "link_only":
        SelectIconIf($db->f(link_only), "../images/linkonly.gif", L_LINK_ONLY,
                                        "../images/spacer.gif", ""); 
        break;  
      case "html_formatted":
        SelectIconIf($db->f(html_formatted), "../images/html.gif", L_HTML_FORMATTED,
                                             "../images/spacer.gif", ""); 
        break;  
      case "highlight":
        SelectIconIf($db->f(highlight), "../images/highlt.gif", L_HIGHLIGHTED,
                                        "../images/spacer.gif", ""); 
        break;  
      case "feed":
        SelectIconIf($db->f(id)!=$db->f(master_id), "../images/feed.gif", L_FEEDED,
                                                    "../images/spacer.gif", ""); 
        break;  
      case "published":
        if( date2sec($db->f(publish_date)) > date2sec(date("Y-m-d"). " 23:59:59") )  //not published, yet
          echo '<td><img src="../images/notpubl.gif" border=0 alt="'. L_NOT_PUBLISHED .'"></td>'; 
        else if( date2sec($db->f(expiry_date)) <= date2sec(date("Y-m-d"). " 0:0:0")) // expired
          echo '<td><img src="../images/expired.gif" border=0 alt="'. L_EXPIRED .'"></td>'; 
        else  // published
          echo '<td><img src="../images/publish.gif" border=0 alt="'. L_PUBLISHED .'"></td>'; 
        break;  
      case "status_code":
        switch( $db->f(status_code) ) {
          case "1": echo '<td><img src="../images/app.gif" border=0 alt="'. L_ACTIVE_BIN .'"></td>'; break;
          case "2": echo '<td><img src="../images/hold.gif" border=0 alt="'. L_HOLDING_BIN .'"></td>'; break;
          case "3": echo '<td><img src="../images/trsh.gif" border=0 alt="'. L_TRASH_BIN .'"></td>'; break;
        }  
        break;  
      case "img_src":
      case "img_width":
      case "img_height":
      case "hl_href":
      case "place":
      case "source":
      case "source_href":
      case "posted_by":
      case "e_posted_by":
      case "created_by":
      case "edited_by":
      case "edit_note":
      case "language_code":
      case "cp_code":
      case "catname":
        if (($foo = $CurItem->f_h($afname)) == "")
          $foo = "&nbsp;";
        echo '<td class=icategory>'. $foo .'</td>';
        break;
      case "chbox":
        echo '<tr><td><input type=checkbox name="chb['. $CurItem->f_n("id") .']" value='.$foo_id.'></td>';
        break;
      case "edit":
        echo '<td><a href="'. con_url($sess->url("itemedit.php3"),"encap=false&edit=1&id=") .$CurItem->f_n("id") .'" class=iheadline>'. L_EDIT . '</a></td>';
        break;
      case "headlinepreview":
        echo '<td><a href="'. con_url($r_slice_view_url,"sh_itm="). $CurItem->f_n("id") .'" target=fullwindow" class=iheadline>'. $CurItem->f_h("headline") .'</a></td>';
        break;
      case "preview":
        echo '<td><a href="'. con_url($r_slice_view_url,"sh_itm="). $CurItem->f_n("id") .'" target=fullwindow" class=iheadline>'. L_VIEW_FULLTEXT .'</a></td>';
        break;
    }
  }  
  echo '</tr>';

  if( $r_bin_show=="long") {          // print additional information
   echo '<tr><td colspan=2>&nbsp;</td>
          <td class=iabstract colspan='. ($col_count-2) .'>'.$CurItem->f_a(abstract).'<br><img src="../images/spacer.gif" width=1 height=5 border=0 alt=""></td></tr>
         <tr><td>&nbsp;</td>
         <td colspan=2 class=ifeed valign=top>';

    // show feeds
    $foo_id = unpack_id( $db->f(master_id) );
    $SQL= "SELECT slices.short_name, items.id, items.master_id, items.status_code FROM slices, items 
             WHERE items.slice_id=slices.id 
               AND items.master_id='". q_pack_id($foo_id) ."' 
               AND items.slice_id<>'$p_slice_id'
             ORDER BY items.status_code, slices.short_name";
    $db2->query($SQL);

    $status_description = array( 1=>L_FEEDED_INTO_APP, 
                                 2=>L_FEEDED_INTO_HOLD, 
                                 3=>L_FEEDED_INTO_TRASH);
    $old_status="x";   //any character except valid status code (1,2,3)
    while ($db2->next_record()) {
      if( $db2->f(id) == $db2->f(master_id) ) {
        $feedfrom = $db2->f(short_name);
        continue;
      }  
      if( $db2->f(status_code) != $old_status ) {
        if( $old_status != "x" )
          echo "</div>";
        $old_status = $db2->f(status_code);
        echo "<div class=ifeed><span class=ifeedtit>".$status_description[$old_status].": </span>".$db2->f(short_name);
      }
      else
        echo ", ".$db2->f(short_name);
    }
    if( $old_status != "x" )  // some slice was displayed
      echo "</div>";
    if( $feedfrom )
      echo "<div class=ifeed><span class=ifeedtit>".L_FEEDED_FROM.": </span>".$feedfrom."</div>";
  
    echo '</td><td colspan='. ($col_count-3) .' align=right>
                 <a href="'. con_url($r_slice_view_url,"sh_itm="). $CurItem->f_n("id") .'" target=fullwindow" class=ipreview>'. L_VIEW_FULLTEXT .'</a><br><br><br>&nbsp;</td></tr>';
  }            
  if(++$i >= $st->metapage) break; 
}
// Bottom ------------------------
echo '<tr><td colspan='.$col_count.'><img src="../images/black.gif" width=460 height=1></td></tr>';
if( $r_bin_show=="long") 
  echo '<tr><td><a href="'. con_url($sess->url($PHP_SELF),"More=short").'"><img src="../images/less.gif" width=18 border=0 alt="'. L_LESS_DETAILS .'"></a></td>'; 
 else 
  echo '<tr><td><a href="'. con_url($sess->url($PHP_SELF),"More=long").'"><img src="../images/more.gif" width=18 border=0 alt="'. L_MORE_DETAILS .'"></a></td>'; 

echo ' <td class=inavbar colspan='.($col_count-1).' align="center">';
if($st->pageCount() > 1)
  $st->pnavbar();
 else 
  echo '&nbsp;';

echo '</td></tr></table>';
echo '<input type=hidden name=action value="">';      // filled by javascript function SubmitItem and SendFeed in feed_to.php3
echo '<input type=hidden name=feed2slice value="">';  // array of comma delimeted slices in which feed to - filled by javascript function SendFeed in feed_to.php3 
echo '<input type=hidden name=feed2app value="">';    // array of comma delimeted slices in which we have to feed into approved - filled by javascript function SendFeed in feed_to.php3 
echo '</form></center>';

echo L_ICON_LEGEND;

echo '
  </body>
</html>';

  $$st_name = $st;   // to save the right scroller 
  page_close();

/*
$Log$
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
