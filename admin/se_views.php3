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

# expected $view_id for editing specified view

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";     // GetAliasesFromField funct def 
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_VIEWS);
  exit;
}  

function PrintView($id, $name, $type) {
  global $sess;

  $name=safe($name); $id=safe($id);     
  echo "<tr class=tabtxt><td>$id</td>
          <td>$name</td>
          <td>". (($type=='list') ? L_COMPACT : L_FULLTEXT) ."</td>
          <td><a href=\"". con_url($sess->url($PHP_SELF),"view_id=$id"). "\">".
            L_EDIT . "</a></td></tr>";
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$p_slice_id = q_pack_id($slice_id);

if( $r_fields )
  $fields = $r_fields;
else
  list($fields,) = GetSliceFields($slice_id);

$ssiuri = ereg_replace("/admin/.*", "/slice.php3", $PHP_SELF); #include help
  
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>". L_A_VIEW_TIT ."</TITLE>";
if( $new_compact ) {     # new compact view
  echo "<SCRIPT Language=\"JavaScript\"><!--
      function InitPage() {
        EnableClick('document.f.even_odd_differ','document.f.even_row_format')
        EnableClick('document.f.category_sort','document.f.category_format')
      }
      function EnableClick(cond,what) {
        eval(what).disabled=!(eval(cond).checked);
        // property .disabled supported only in MSIE 4.0+
      }   
      // -->
      </SCRIPT>";
}
echo "</HEAD>";

$xx = ($slice_id!="");
$useOnLoad = ($new_compact ? true : false);
$show = Array("main"=>true, "slicedel"=>$xx, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
              "views"=>false, "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

echo "<H1><B>" . L_A_VIEWS . "</B></H1>";
PrintArray($err);
echo $Msg;
?>
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_VIEWS_HDR?></b><BR>
</td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  
# -- get views for current slice --
$SQL = "SELECT * FROM view WHERE slice_id='$p_slice_id'";
$db->query($SQL);
while( $db->next_record() )
  PrintView($db->f(id), $db->f(name), $db->f(type));

  # row for new view
echo "<tr class=tabtit><td align=center colspan=2>
        <a href=\"". con_url($sess->url($PHP_SELF),"new_compact=1"). "\">".
            L_NEW_COMPACT . "</a></td>
          <td align=center colspan=2>
        <a href=\"". con_url($sess->url($PHP_SELF),"new_full=1"). "\">".
            L_NEW_FULLTEXT . "</a></td></tr>
  </table></td></tr></table><br>";

# -- get slice fields
$s_fields[0] = L_NONE;
$SQL = "SELECT id, name FROM field WHERE slice_id='$p_slice_id' ORDER BY pri");
$db->query($SQL);
while($db->next_record())
  $s_fields[$db->f(id)] = $db->f(name);

if( $update )
{
  if( $new_compact ) {
    do
    {
      ValidateInput("name", L_NAME, &$name, &$err, true, "text");
      ValidateInput("odd_row_format", L_ODD_ROW_FORMAT, &$odd_row_format, &$err, true, "text");
      ValidateInput("compact_top", L_COMPACT_TOP, &$compact_top, &$err, false, "text");
      ValidateInput("compact_bottom", L_COMPACT_BOTTOM, &$compact_bottom, &$err, false, "text");
      ValidateInput("compact_remove", L_COMPACT_REMOVE, &$compact_remove, &$err, false, "text");
      ValidateInput("listlen", L_LISTLEN, &$listlen, &$err, false, "number");
      if( $even_odd_differ )
        ValidateInput("even_row_format", L_EVEN_ROW_FORMAT, &$even_row_format, &$err, true, "text");
      if( $group_by1 AND ($group_by1 != 0) ) {
        ValidateInput("category_format", L_CATEGORY_FORMAT, &$category_format, &$err, true, "text");
      }  
      if( count($err) > 1)
        break;
  
      $varset->add("slice_id", "unpacked", $slice_id);
      $varset->add("name", "quoted", $name);
      $varset->add("type", "quoted", "compact");
      $varset->add("format", "quoted", $odd_row_format);
      $varset->add("even_row_format", "quoted", $even_row_format);
      $varset->add("category_format", "quoted", $category_format);
      $varset->add("top", "quoted", $compact_top);
      $varset->add("bottom", "quoted", $compact_bottom);
      $varset->add("remove", "quoted", $compact_remove);
      $varset->add("even_odd_differ", "number", $even_odd_differ ? 1 : 0);
      $varset->add("group_by1", "quoted", ($group_by1==0)? "" : $group_by1);
      $varset->add("sort_by1", "quoted", ($sort_by1==0)? "" : $sort_by1);
      $varset->add("sort_by2", "quoted", ($sort_by2==0)? "" : $sort_by2);
      $varset->add("group_by1_desc", "number", $group_by1_desc ? 1 : 0);
      $varset->add("sort_by1_desc", "number", $sort_by1_desc ? 1 : 0);
      $varset->add("sort_by2_desc", "number", $sort_by2_desc ? 1 : 0);
      $varset->add("listlen", "number", $listlen);
  
      if( $view_id ) {
        if( !$db->query("UPDATE view SET ". $varset->makeUPDATE() . " 
                        WHERE id='$view_id'")) {
          $err["DB"] = MsgErr( L_ERR_CANT_CHANGE );
          break;   # not necessary - we have set the halt_on_error
        }
      } else {  
        if( !$db->query("INSERT INTO view ". $varset->makeINSERT())) {
          $err["DB"] = MsgErr( L_ERR_CANT_ADD );
          break;   # not necessary - we have set the halt_on_error
        }
      }
      $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
      $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    }while(false);
  } elseif( $new_full ) {
    do
    {
      ValidateInput("name", L_NAME, &$name, &$err, true, "text");
      ValidateInput("fulltext_top", L_FULLTEXT_TOP, &$fulltext_top, &$err, false, "text");
      ValidateInput("fulltext_format", L_FULLTEXT_FORMAT, &$fulltext_format, &$err, false, "text");
      ValidateInput("fulltext_bottom", L_FULLTEXT_BOTTOM, &$fulltext_bottom, &$err, false, "text");
      ValidateInput("fulltext_remove", L_FULLTEXT_REMOVE, &$fulltext_remove, &$err, false, "text");
      ValidateInput("listlen", L_LISTLEN, &$listlen, &$err, false, "number");
      if( count($err) > 1)
        break;
  
      $varset->add("slice_id", "unpacked", $slice_id);
      $varset->add("name", "quoted", $name);
      $varset->add("type", "quoted", "fulltext");
      $varset->add("format", "quoted", $fulltext_format);
      $varset->add("top", "quoted", $fulltext_top);
      $varset->add("bottom", "quoted", $fulltext_bottom);
      $varset->add("remove", "quoted", $fulltext_remove);
  
      if( $view_id ) {
        if( !$db->query("UPDATE view SET ". $varset->makeUPDATE() . " 
                        WHERE id='$view_id'")) {
          $err["DB"] = MsgErr( L_ERR_CANT_CHANGE );
          break;   # not necessary - we have set the halt_on_error
        }
      } else {  
        if( !$db->query("INSERT INTO view ". $varset->makeINSERT())) {
          $err["DB"] = MsgErr( L_ERR_CANT_ADD );
          break;   # not necessary - we have set the halt_on_error
        }
      }
      $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
      $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    }while(false);
  }  

  if( count($err) <= 1 )
    $Msg = MsgOK(L_VIEW_OK);
}

if( $view_id AND !$update ) {  // set variables from database
  $SQL= " SELECT * FROM view WHERE view_id='$view_id'";
  $db->query($SQL);
  if ($db->next_record()) {
    $name =  = $db->f(name);
    $type =  = $db->f(type);
    $odd_row_format = $db->f(format);
    $even_row_format = $db->f(even_row_format);
    $category_format = $db->f(category_format);
    $compact_top = $db->f(top);
    $compact_bottom = $db->f(bottom);
    $compact_remove = $db->f(remove);
    $even_odd_differ = $db->f(even_odd_differ);
    $group_by1 = $db->f(group_by1);
    $sort_by1 = $db->f(sort_by1);
    $sort_by2 = $db->f(sort_by2);
    $group_by1_desc = $db->f(group_by1_desc);
    $sort_by1_desc = $db->f(sort_by1_desc);
    $sort_by2_desc = $db->f(sort_by2_desc);
    $listlen = $db->f(listlen);
    
    $fulltext_format = $odd_row_format
    $fulltext_top = $compact_top;
    $fulltext_bottom = $compact_bottom;
    $fulltext_remove = $compact_remove;
  }  
}

if( $new_compact ) {
  echo "
  <form name=f enctype='multipart/form-data' method=post action='". $sess->url($PHP_SELF) ."'>
  <table width='440' border='0' cellspacing='0' cellpadding='1' bgcolor='". COLOR_TABTITBG ."' align='center'>
   <tr><td class=tabtit><b>&nbsp;" L_COMPACT ."</b><BR>
    </td></tr>
   <tr><td>
  <table width='100%' border='0' cellspacing='0' cellpadding='4' bgcolor='". COLOR_TABBG ."'>";

  FrmStaticText(L_ID, $view_id);
  FrmInputText("name", L_NAME, $name, 254, 50, false,
               L_NAME_HLP, DOCUMENTATION_URL);
  FrmTextarea("compact_top", L_COMPACT_TOP, $compact_top, 4, 50, false,
               L_TOP_HLP, "", DOCUMENTATION_URL, 1);
  FrmTextarea("odd_row_format", L_ODD_ROW_FORMAT, $odd_row_format, 6, 50, false,
               L_FORMAT_HLP, "", DOCUMENTATION_URL, 1); 
  FrmInputChBox("even_odd_differ", L_EVEN_ODD_DIFFER, $even_odd_differ, true, "OnClick=\"EnableClick('document.f.even_odd_differ','document.f.even_row_format')\"");
  FrmTextarea("even_row_format", L_EVEN_ROW_FORMAT, $even_row_format, 6, 50, false,
               L_EVEN_ROW_HLP, "", DOCUMENTATION_URL, 1); 
  FrmTextarea("compact_bottom", L_COMPACT_BOTTOM, $compact_bottom, 4, 50, false,
               L_BOTTOM_HLP, "", DOCUMENTATION_URL, 1); 
  FrmInputText("listlen", L_LISTLEN, $listlen, false);

  FrmInputSelect("sort_by1", L_SORT_BY1, $s_fields, $sort_by1, false,
                 L_SORT_BY1_HLP, DOCUMENTATION_URL); 
  FrmInputChBox("sort_by1_desc", L_SORT_BY1_DESC, $sort_by1_desc, true);
  FrmInputSelect("sort_by2", L_SORT_BY1, $s_fields, $sort_by2, false,
                 L_SORT_BY2_HLP, DOCUMENTATION_URL); 
  FrmInputChBox("sort_by2_desc", L_SORT_BY2_DESC, $sort_by2_desc, true);

  FrmInputSelect("group_by1", L_GROUP_BY1, $s_fields, $group_by1, false,
                 L_GROUP_BY1_HLP, DOCUMENTATION_URL); 
  FrmInputChBox("group_by1_desc", L_GROUP_BY1_DESC, $group_by1_desc, true);
  FrmTextarea("category_format", L_CATEGORY_FORMAT, $category_format, 6, 50, false,
               L_FORMAT_HLP, "", DOCUMENTATION_URL, 1); 
  FrmInputText("compact_remove", L_COMPACT_REMOVE, $compact_remove, 254, 50, false,
               L_REMOVE_HLP, DOCUMENTATION_URL);
  echo "</table></td></tr>";
  PrintAliasHelp(GetAliasesFromFields($fields));
  echo "<tr><td align='center'>
        <input type=hidden name='new_compact' value=1>
        <input type=hidden name='update' value=1>
        <input type=submit name=update value='". L_UPDATE ."'>&nbsp;&nbsp;<input
               type=submit name=cancel value='". L_CANCEL ."'>
       </td></tr></table>
      </FORM><br>";
      
  if( $view_id ) {
    echo L_SLICE_HINT ."<br><pre>&lt;!--#include virtual=&quot;" . $ssiuri . 
         "?slice_id=$slice_id&apm;view_id=$view_id&quot;--&gt;</pre>";
  }    
} elseif( $new_full ) {
  echo "
  <form name=f enctype='multipart/form-data' method=post action='". $sess->url($PHP_SELF) ."'>
  <table width='440' border='0' cellspacing='0' cellpadding='1' bgcolor='". COLOR_TABTITBG ."' align='center'>
   <tr><td class=tabtit><b>&nbsp;" L_FULLTEXT ."</b><BR>
    </td></tr>
   <tr><td>
  <table width='100%' border='0' cellspacing='0' cellpadding='4' bgcolor='". COLOR_TABBG ."'>";

  FrmStaticText(L_ID, $view_id);
  FrmInputText("name", L_NAME, $name, 254, 50, false,
               L_NAME_HLP, DOCUMENTATION_URL);
  FrmTextarea("fulltext_top", L_FULLTEXT_TOP, $fulltext_top, 4, 50, false,
               L_TOP_HLP, "", DOCUMENTATION_URL, 1);
  FrmTextarea("fulltext_format", L_FULLTEXT_FORMAT, $fulltext_format, 6, 50, false,
               L_FORMAT_HLP, "", DOCUMENTATION_URL, 1); 
  FrmTextarea("fulltext_bottom", L_FULLTEXT_BOTTOM, $fulltext_bottom, 4, 50, false,
               L_BOTTOM_HLP, "", DOCUMENTATION_URL, 1); 
  FrmInputText("fulltext_remove", L_FULLTEXT_REMOVE, $fulltext_remove, 254, 50, false,
               L_REMOVE_HLP, DOCUMENTATION_URL);
  echo "</table></td></tr>";
  PrintAliasHelp(GetAliasesFromFields($fields));
  echo "<tr><td align='center'>
        <input type=hidden name='new_full' value=1>
        <input type=hidden name='update' value=1>
        <input type=submit name=update value='". L_UPDATE ."'>&nbsp;&nbsp;<input
               type=submit name=cancel value='". L_CANCEL ."'>
       </td></tr></table>
      </FORM><br>";

  if( $view_id ) {
    echo L_SLICE_HINT ."<br><pre>&lt;!--#include virtual=&quot;" . $ssiuri . 
         "?slice_id=$slice_id&apm;view_id=$view_id&quot;--&gt;</pre>";
  }    

}      
      
echo "</BODY></HTML>";
page_close();
/*
$Log$
Revision 1.3  2001/03/20 15:27:03  honzam
Changes due to "slice delete" feature

Revision 1.2  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.1  2001/02/26 17:26:08  honzam
color profiles

*/
?>


