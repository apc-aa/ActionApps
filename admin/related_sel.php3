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

# sid expected - slice_id where to search
# var_id expected - id of variable in calling form, which should be filled
# mode expected - which buttons to show ([A][M][B] - 'add' 'add mutual' 'add backward'
# design expected - boolean - use standard or admin design

$save_hidden = true;   # do not delete r_hidden session variable in init_page!

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

$sess->register(r_sid);
if( $sid )
  $r_sid = $sid;


$slice_info = GetSliceInfo($r_sid);
$config_arr = unserialize( $slice_info["config"] );

// $r_r_admin_order, $r_r_admin_order - controls article ordering 
// $r_r_admin_order contains field id
// $r_r_admin_order_dir contains 'd' for descending order, 'a' for ascending
if(!isset($r_r_admin_order)) {
  $r_r_admin_order = ( $config_arr["admin_order"] ? 
                     $config_arr["admin_order"] : "publish_date...." );
  $r_r_admin_order_dir = ( $config_arr["admin_order_dir"] ? 
                         $config_arr["admin_order_dir"] : "d" );
  $sess->register(r_r_admin_order); 
  $sess->register(r_r_admin_order_dir); 

  // $r_r_admin_search, $r_r_admin_search_field - controls article filter
  // $r_r_admin_search contains search string
  // $r_r_admin_search_field contains field id
  $sess->register(r_r_admin_search); 
  $sess->register(r_r_admin_search_field); 
}

$p_sid= q_pack_id($r_sid);

if( $r_fields )
  $fields = $r_fields;
else
  list($fields,) = GetSliceFields($r_sid);

if( $akce == "filter" ) { // edit the first one
    $r_r_admin_order = ( $admin_order ? $admin_order : "publish_date...." );
    $r_r_admin_order_dir = ( $admin_order_dir ? "d" : "a");
    
    $r_r_admin_search = $admin_search;
    $r_r_admin_search_field = $admin_search_field;
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Editor window - item manager") ?></title>
<SCRIPT Language="JavaScript"><!--
  function ReplaceFirstChar( str, ch ) {
    return   ch + str.substring(1,str.length);
  }

  function AddSelectOption( starget, stext, svalue ) {
    for( i=0; i < <?php echo MAX_RELATED_COUNT ?>; i++ )
      if( eval(starget).options[i].value == 'wIdThTor' )
        break;
    if( i < <?php echo MAX_RELATED_COUNT ?> ) {
      eval(starget).options[i].text = stext;
      eval(starget).options[i].value = svalue;
    } else 
      alert( "<?php echo _m("There are too many related items. The number of related items is limited."); # the maximum number of related stories is here jut because
                                             # we can't create new Option in to itemedit.hpp3 window
                                             # from this window in JavaScript 
                                             ?>" );
  }    

function SelectRelations(tag, prefix, taggedid, headline) {
    AddSelectOption( 'window.opener.document.inputform.elements["<?php echo $var_id ?>"]', 
                prefix + headline, taggedid); 
  }

// -->
</SCRIPT>
</head> <?php

$de = getdate(time());

  # ACTIVE | EXPIRED | PENDING | HOLDING | TRASH | ALL
$st_name = "st_rel";    // name of scroller for related selection table
$bin_condition = 'ACTIVE';
$table_name = _m("Select related items");

$st = $$st_name;   // use right scroller

# create or update scroller for actual bin
if(is_object($st)) {
  $st->updateScr($sess->url($PHP_SELF) . "&var_id=$var_id&");
}else {
  $st = new scroller($st_name, $sess->url($PHP_SELF) . "&var_id=$var_id&");	
  $st->metapage=($listlen ? $listlen : EDIT_ITEM_COUNT);
  $sess->register($st_name); 
}

# if user sets search condition
if( $r_r_admin_search )
  $conds[]=array( 'operator' => 'LIKE',
                  'value' => $r_r_admin_search,
                  $r_r_admin_search_field => 1 );

# set user defined sort order
$sort[] = array ( $r_r_admin_order => $r_r_admin_order_dir); 

$zids=QueryZIDs($fields, $r_sid, $conds, $sort, "", $bin_condition);

global $tps;  # Defined in itemfunc.php3

# mode - which buttons to show ([A][M][B] - 'add' 'add mutual' 'add backward'
if( !$mode )
  $mode='AMB';
for( $i=0; $i<strlen($mode); $i++) {
    $m1 = substr($mode,$i,1);
    $mode_string .= "&nbsp;<a href=\"javascript:SelectRelations('".$tps['AMB'][$m1]['tag']."','".$tps['AMB'][$m1]['prefix']."','".$tps['AMB'][$m1]['tag']."_#ITEM_ID_','_#HEADLINE')\">". $tps['AMB'][$m1]['str'] ."</a>&nbsp;";
}   


$format_strings = array ( "compact_top"=>"",
                          "category_sort"=>false,
                          "category_format"=>"",
                          "category_top"=>"",
                          "category_bottom"=>"",
                          "even_odd_differ"=>false,
                          "even_row_format"=>"",
                          "odd_row_format"=>"<tr><td class=tabtxt>_#HEADLINE</td>
                                                 <td class=tabtxt>$mode_string</td>
                                             </tr>",
                          "compact_remove"=>"",
                          "compact_bottom"=>"",
                          "id"=>$slice_info['id']);

# design - boolean - use standard or admin design
if($design) {
  $format_strings["compact_top"]    = $slice_info['admin_format_top'];
  $format_strings["odd_row_format"] = str_replace('<input type=checkbox name="chb[x_#ITEM_ID#]" value="1">', 
                                                  $mode_string, $slice_info['admin_format']);
  $format_strings["compact_remove"] = $slice_info['admin_remove'];
  $format_strings["compact_bottom"] = $slice_info['admin_format_bottom'];
}  



echo "<center>";
echo "$Msg <br>";

# ------- Caption -----------

echo "<table border=0 cellspacing=0 class=login width=460>
       <TR><TD align=center class=tablename width=460> $table_name </TD></TR>
      </table>";

echo '<form name="itemsform" method=post action="'. $sess->url($PHP_SELF) .'">'.
'<table width="460" border="0" cellspacing="0" cellpadding="2" bgcolor="#F5F0E7">';

if( isset($zids) && ($zids->count() > 0) ) {
  if( $design )
    $aliases = GetAliasesFromFields($fields);
   else {                     # define just used aliases, including HEADLINE
    $aliases["_#ITEM_ID_"] = array("fce" => "f_n:id..............",
                                   "param" => "id..............",
                                   "hlp" => "");
    $aliases["_#SITEM_ID"] = array("fce" => "f_h",
                                   "param" => "short_id........",
                                   "hlp" => "");
    $aliases["_#HEADLINE"] = array("fce" => "f_e:safe",
                                   "param" => GetHeadlineFieldID($r_sid, $db),
                                   "hlp" => "");
  }                                 

  $itemview = new itemview( $db, $format_strings, $fields, $aliases, $zids,
              $st->metapage * ($st->current-1), $st->metapage, "" );
  $itemview->print_view();
    
  $st->countPages( $zids->count() );

  echo '</table><br>';

	if($st->pageCount() > 1)
    $st->pnavbar();
}  
else 
  echo "<tr><td><div class=tabtxt>". _m("No item found") ."</div></td></td></table>";
  
echo '<input type=hidden name=akce value="">';      // filled by javascript function SubmitItem and SendFeed in feed_to.php3
echo '</form>';

# user defined sorting and filtering ---------------------------------------
echo '<form name=filterform method=post action="'. $sess->url($PHP_SELF). '">
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
       <td class=leftmenuy><b>". _m("Order") ."</b></td>
       <td class=leftmenuy>";
FrmSelectEasy('admin_order', $lookup_fields, $r_r_admin_order);
echo "<input type='checkbox' name='admin_order_dir'". 
     ( ($r_r_admin_order_dir=='d') ? " checked> " : "> " ) . _m("Descending"). "</td>
     <td rowspan=2 align='right' valign='middle'><a
      href=\"javascript:document.filterform.submit()\" class=leftmenuy>". _m("Go") ."</a> </td></tr>";

  # filter
echo "<tr><td class=leftmenuy><b>". _m("Search") ."</b></td>
     <td>";
FrmSelectEasy('admin_search_field', $lookup_text_fields, $r_r_admin_search_field);
echo "<input type='Text' name='admin_search' size=20
      maxlength=254 value=\"". safe($r_r_admin_search). "\"></td></tr></table>
      <input type=hidden name=var_id value='$var_id'><br><br>
      <input type=hidden name=akce value='filter'><br><br>
      <input type=button value='". _m("Back") ."' onclick='window.close()'>
      </form></center>";
  echo "</body></html>";

  $$st_name = $st;   // to save the right scroller 
  page_close();
?>
