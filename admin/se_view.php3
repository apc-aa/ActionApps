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

# expected $view_type for both - new and edit
# expected $view_id for editing specified view or $new

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

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$p_slice_id = q_pack_id($slice_id);

  
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>". L_A_VIEW_TIT ."</TITLE>
      <SCRIPT Language=\"JavaScript\"><!--
      function InitPage() {
        EnableClick('document.f.even_odd_differ','document.f.even_row_format')
        EnableClick('document.f.category_sort','document.f.category_format')
      }
      function EnableClick(cond,what) {
        eval(what).disabled=!(eval(cond).checked);
        // property .disabled supported only in MSIE 4.0+
      }   
      // -->
      </SCRIPT>
    </HEAD>";

$xx = ($slice_id!="");
$useOnLoad = ($VIEW_TYPES[$type]["even_odd_differ"] ? true : false);
$show = Array("main"=>true, "slicedel"=>$xx, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
              "views"=>true, "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
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
  
# -- get slice fields
$s_fields[0] = L_NONE;
$SQL = "SELECT id, name FROM field WHERE slice_id='$p_slice_id' ORDER BY pri");
$db->query($SQL);
while($db->next_record())
  $s_fields[$db->f(id)] = $db->f(name);

if( $update )
{
  do
  {
    reset($VIEW_FIELDS)
    while(list($k, $v) = each($VIEW_FIELDS)) {
      if( $v["validate"] AND $VIEW_TYPES[$view_type][$k] )
        ValidateInput($k, $VIEW_TYPES[$view_type][$k], &$$k, &$err, false, $v["validate"]);
    }  
    if( count($err) > 1)
      break;
  
    $varset->add("slice_id", "unpacked", $slice_id);
    $varset->add("name", "quoted", $name);
    $varset->add("type", "quoted", $view_type);

    reset($VIEW_FIELDS)
    while(list($k, $v) = each($VIEW_FIELDS)) {
      if( $VIEW_TYPES[$view_type][$k] )
        $varset->add($k, $v["insert"], (($v["type"]=="bool") ? ($$k ? 1 : 0) 
                                                             : $$k);
    }  

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

  if( count($err) <= 1 )
    $Msg = MsgOK(L_VIEW_OK);
}

if( !$update ) {  # set variables from database
  if( $view_id )  # edit specified view data
    $SQL= " SELECT * FROM view WHERE id='$view_id'";
   else           # new view - get default values from view table - 
                  #            take first view of the same type
    $SQL= " SELECT * FROM view WHERE view_type='$view_type' ORDER by id";
  $db->query($SQL);
  if ($db->next_record()) 
    $vw_data = $db->Record;
} elseif {        # updating - load data into vw_data array
  reset($VIEW_FIELDS)
  while(list($k, $v) = each($VIEW_FIELDS)) {
    if( $VIEW_TYPES[$view_type][$k] )
      $vw_data[$k] = $$k;
  }  
}

# operators array
$lookup_op = array( "<"  => "<", 
                    "<=" => "<=", 
                    "="  => "=", 
                    "<>" => "<>", 
                    ">"  => ">",
                    ">=" => ">=" );

# lookup group of constatnts
$lookup_groups = GetConstants('lt_groupNames', $db);

# lookup slice fields
$db->query("SELECT id, name FROM field
             WHERE slice_id='$p_slice_id' ORDER BY name");
while($db->next_record())
  $lookup_fields[$db->f(id)] = $db->f(name);


echo "
<form name=f enctype='multipart/form-data' method=post action='". $sess->url($PHP_SELF) ."'>
<table width='440' border='0' cellspacing='0' cellpadding='1' bgcolor='". COLOR_TABTITBG ."' align='center'>
 <tr><td class=tabtit><b>&nbsp;" L_COMPACT ."</b><BR>
  </td></tr>
 <tr><td>
<table width='100%' border='0' cellspacing='0' cellpadding='4' bgcolor='". COLOR_TABBG ."'>";

FrmStaticText(L_ID, $view_id);

reset($VIEW_TYPES[$view_type])
while(list($k, $v) = each($VIEW_TYPES[$view_type])) {
  switch ( $VIEW_FIELDS[$k]["input"] ) {
    case "field":   FrmInputText($k, $v, $vw_data($k), 254, 50, false, "", DOCUMENTATION_URL); break;
    case "area":    FrmTextarea($k, $v, $vw_data($k), 4, 50, false, "", "", DOCUMENTATION_URL, 1); break;
    case "seltype": FrmInputSelect($k, $v, $VIEW_TYPES_TYPES[$view_type], $vw_data($k), false, "", DOCUMENTATION_URL); break;
    case "selfld":  FrmInputSelect($k, $v, $lookup_fields, $vw_data($k), false, "", DOCUMENTATION_URL); break;
    case "selgrp":  FrmInputSelect($k, $v, $lookup_groups, $vw_data($k), false, "", DOCUMENTATION_URL); break;
    case "op":      FrmInputSelect($k, $v, $lookup_op, $vw_data($k), false, "", DOCUMENTATION_URL); break;
    case "chbox":   FrmInputChBox($k, $v, $vw_data($k), true); break;
}  

echo "</table></td></tr>";
echo "<tr><td align='center'>
      <input type=submit name=update value='". L_UPDATE ."'>&nbsp;&nbsp;<input
             type=submit name=cancel value='". L_CANCEL ."'>
     </td></tr></table>
    </FORM><br>";
    
if( $view_id ) {
  $ssiuri = ereg_replace("/admin/.*", "/view.php3", $PHP_SELF);
  echo L_SLICE_HINT ."<br><pre>&lt;!--#include virtual=&quot;" . $ssiuri . 
       "?vid=$view_id&quot;--&gt;</pre>";
}    
echo "</BODY></HTML>";
page_close();
/*
$Log$
Revision 1.1  2001/05/10 10:01:43  honzam
New spanish language files, removed <form enctype parameter where not needed, better number validation

*/
?>


