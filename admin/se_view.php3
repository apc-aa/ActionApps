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
require $GLOBALS[AA_INC_PATH]."discussion.php3";  // GetDiscussionAliases funct def

function OrderFrm($name, $txt, $val, $order_fields) {
  global $vw_data;
  $name=safe($name); $txt=safe($txt);
  echo "<tr><td class=tabtxt><b>$txt</b> ";
  if (!SINGLE_COLUMN_FORM)
    echo "</td>\n<td>";
  FrmSelectEasy($name, $order_fields, $val);
     # direction variable name - construct from $name
  $dirvarname = substr($name,0,1).substr($name,-1)."_direction";
  FrmSelectEasy($dirvarname, array( '0'=>L_ASCENDING, '1' => L_DESCENDING ), 
                $vw_data[$dirvarname]);

  PrintMoreHelp(DOCUMENTATION_URL);
//  PrintHelp($hlp);
  echo "</td></tr>\n";
}  

function ConditionFrm($name, $txt, $val) {
  global $lookup_fields, $lookup_op, $vw_data;
  $name=safe($name); $txt=safe($txt);
  echo "<tr><td class=tabtxt><b>$txt</b> ";
  if (!SINGLE_COLUMN_FORM)
    echo "</td>\n<td>";
  FrmSelectEasy($name, $lookup_fields, $val);
     # direction variable name - construct from $name
  $opvarname = substr($name,0,5)."op";
  FrmSelectEasy($opvarname, $lookup_op, $vw_data[$opvarname]);
     # direction variable name - construct from $name
  if (!SINGLE_COLUMN_FORM)
    echo "</td></tr>\n<tr><td>&nbsp;</td><td>";
   else 
    echo "</td></tr>\n<tr><td>&nbsp; &nbsp;";

  $condvarname = substr($name,0,5)."cond";
  echo "<input type=\"Text\" name=\"$condvarname\" size=50
          maxlength=254 value=\"". safe($vw_data[$condvarname]) ."\">";

  PrintMoreHelp(DOCUMENTATION_URL);
//  PrintHelp($hlp);
  echo "</td></tr>\n";
}  

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_VIEWS, "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$p_slice_id = q_pack_id($slice_id);

if( $update )
{
  do
  {
    reset($VIEW_FIELDS);
    while(list($k, $v) = each($VIEW_FIELDS)) {
      if( $v["validate"] AND $VIEW_TYPES[$view_type][$k] )
        ValidateInput($k, $VIEW_TYPES[$view_type][$k], &$$k, &$err, false, $v["validate"]);
    }  
    if( count($err) > 1)
      break;
  
    $varset->add("slice_id", "unpacked", $slice_id);
    $varset->add("name", "quoted", $name);
    $varset->add("type", "quoted", $view_type);

    reset($VIEW_FIELDS);
    while(list($k, $v) = each($VIEW_FIELDS)) {
      if( $VIEW_TYPES[$view_type][$k] ) {
        $varset->add($k, $v["insert"], (($v["type"]=="bool") ? ($$k ? 1 : 0) 
                                                                      : $$k));
      }                                                       
    }  
    if( $view_id ) {
      $SQL = "UPDATE view SET ". $varset->makeUPDATE() ." WHERE id='$view_id'";
      if( !$db->query($SQL)) {
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
    go_url( $sess->url(self_base() . "se_views.php3"));
  }while(false);

  if( count($err) <= 1 )
    $Msg = MsgOK(L_VIEW_OK);
}

if( !$update ) {  # set variables from database
  if( $view_id )  # edit specified view data
    $SQL= " SELECT * FROM view WHERE id='$view_id'";
   else {         # new view - get default values from view table - 
                  #            take first view of the same type
    if( $view_type )
      $SQL= " SELECT * FROM view WHERE type='$view_type' ORDER by id";
     else         # error - someone swith the slice or so
      go_url($sess->url("se_views.php3")); 
  }    
  $db->query($SQL);
  if ($db->next_record())
    $vw_data = $db->Record;
   else
    $vw_data = array( "listlen" => 10 );   # default values
} else {        # updating - load data into vw_data array
  reset($VIEW_FIELDS);
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
                    ">=" => ">=",
                    "LIKE"  => "substring (LIKE)",
                    "RLIKE"  => "begins with ... (RLIKE)",
                    "ISNULL"  => "not set",
                    "m:<" => "< now() - x [in seconds]",
                    "m:>" => "> now() - x [in seconds]");

# lookup group of constatnts
$lookup_groups = GetConstants('lt_groupNames', $db, 'name');

# lookup slice fields
$db->query("SELECT id, name FROM field
             WHERE slice_id='$p_slice_id' ORDER BY name");
$lookup_fields[''] = " ";  # default - none
while($db->next_record())
  $lookup_fields[$db->f(id)] = $db->f(name);


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

$useOnLoad = ($VIEW_TYPES[$type]["even_odd_differ"] ? true : false);
$show ["views"] = true;
require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

echo "<H1><B>" . L_A_VIEWS . "</B></H1>";
PrintArray($err);
echo $Msg;

?>
<form name=f enctype='multipart/form-data' method=post action='<?php echo $sess->url($PHP_SELF) ?>'>
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_VIEWS_HDR?></b><BR>
</td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php

FrmStaticText(L_ID, $view_id );

echo "<input type=hidden name='view_type' value='$view_type'>";

reset($VIEW_TYPES[$view_type]);
while(list($k, $v) = each($VIEW_TYPES[$view_type])) {
  if( !($value = $vw_data[$k]) && $VIEW_TYPES_INFO[$view_type][$k]['default'] )  // we can define default values for fields (see constants.php3)
    $value = $VIEW_TYPES_INFO[$view_type][$k]['default'];
  switch ( $VIEW_FIELDS[$k]["input"] ) {
    case "field":   FrmInputText($k, $v, $value, 254, 50, false, "", DOCUMENTATION_URL); break;
    case "area":    FrmTextarea($k, $v, $value, 4, 50, false, "", DOCUMENTATION_URL); break;
    case "seltype": FrmInputSelect($k, $v, $VIEW_TYPES_INFO[$view_type][modification], $value, false, "", DOCUMENTATION_URL); break;
    case "selfld":  FrmInputSelect($k, $v, $lookup_fields, $value, false, "", DOCUMENTATION_URL); break;
    case "selgrp":  FrmInputSelect($k, $v, $lookup_groups, $value, false, "", DOCUMENTATION_URL); break;
    case "op":      FrmInputSelect($k, $v, $lookup_op, $value, false, "", DOCUMENTATION_URL); break;
    case "chbox":   FrmInputChBox($k, $v, $value, true); break;
    case "cond":    ConditionFrm($k, $v, $value); break;
    case "order":   OrderFrm($k, $v, $value, $VIEW_TYPES_INFO[$view_type][order] ? 
                                     $VIEW_TYPES_INFO[$view_type][order] : $lookup_fields); break;
    case "select":  FrmInputSelect($k, $v, $VIEW_FIELDS[$k]["values"], $vw_data[$k], false, "", DOCUMENTATION_URL); break;
    case "none": break;
  }
}  
echo "</table></td></tr>";

switch( $VIEW_TYPES_INFO[$view_type]['aliases'] ) {
  case 'field': if ($view_type == 'discus') {
                   PrintAliasHelp(GetDiscussionAliases());
                } else {
                  if( $r_fields )
                    $fields = $r_fields;
                  else
                    list($fields,) = GetSliceFields($slice_id);
                  PrintAliasHelp(GetAliasesFromFields($fields, $VIEW_TYPES_INFO[$view_type]['aliases_additional']));
                }
                break;
  case 'const': PrintAliasHelp(GetConstantAliases());
                break;
  case 'none':  break;
}                

echo "<tr><td align='center'>
      <input type=hidden name=view_id value='$view_id'>
      <input type=submit name=update value='". L_UPDATE ."'>&nbsp;&nbsp;<input
             type=submit name=cancel value='". L_CANCEL ."'></td></tr></table>
    </FORM><br>";
    
if( $view_id ) {
  $ssiuri = ereg_replace("/admin/.*", "/view.php3", $PHP_SELF);
  echo L_SLICE_HINT ."<br><pre>&lt;!--#include virtual=&quot;" . $ssiuri . 
       "?vid=$view_id&quot;--&gt;</pre>";
}    
echo "</BODY></HTML>";
page_close();
?>