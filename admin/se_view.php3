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

require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."item.php3";     // GetAliasesFromField funct def 
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."discussion.php3";  // GetDiscussionAliases funct def
require_once $GLOBALS["AA_INC_PATH"]."msgpage.php3";

function show_digest_filters ()
{
    global $view_id;
    $db = new DB_AA;
    $db->query("SELECT * FROM alerts_filter WHERE vid=".($view_id ? $view_id : 0));
    $rows = $db->num_rows();
    for ($irow = 0; $irow < $rows+2; $irow ++) {
        if ($irow <= $db->num_rows()) $db->next_record();
        $rowid = $db->f("id");
        if (!$rowid) $rowid = "new$irow";
        FrmInputText("filters[$rowid][description]", _m("Alerts Selection")." ".($irow+1)." "._m("Description"), $db->f("description"), 100, 50, false);
        $condrows = 1 + strlen ($db->f("conds")) / 50;
        if ($condrows > 4) $condrows = 4;
        FrmTextarea("filters[$rowid][conds]", "conds[]", $db->f("conds"), $condrows, 50, false); 
    }
}

function store_digest_filters ()
{
    global $view_id, $filters, $err;
    $db = new DB_AA;
    if (!$view_id) {
   		$db->query("SELECT LAST_INSERT_ID() AS last_vid FROM view");
        $db->next_record();
        $view_id = $db->f("last_vid");
    }
    $varset = new CVarset();
    reset ($filters);
    while (list ($rowid, $filter) = each ($filters)) {
        if (!$filter["description"]) {
            if (substr ($rowid,0,3) != "new")
                $db->query("DELETE FROM alerts_filter WHERE id=$rowid");
            continue;
        }    
        $varset->clear();
        $varset->add("description", "quoted", $filter["description"]);
        $varset->add("conds", "quoted", $filter["conds"]);
        $varset->add("vid", "number", $view_id);

        if (substr ($rowid,0,3) != "new") 
            $SQL = "UPDATE alerts_filter SET ". $varset->makeUPDATE() ." WHERE id='$rowid'";
        else $SQL = "INSERT INTO alerts_filter ".$varset->makeINSERT();
        if( !$db->query($SQL)) {
            $err["DB"] = MsgErr( _m("Can't change slice settings") );
            break;   # not necessary - we have set the halt_on_error
        }
    }
}       

function OrderFrm($name, $txt, $val, $order_fields) {
  global $vw_data;
  $name=safe($name); $txt=safe($txt);
  echo "<tr><td class=tabtxt><b>$txt</b> ";
  if (!SINGLE_COLUMN_FORM)
    echo "</td>\n<td>";
  FrmSelectEasy($name, $order_fields, $val);
     # direction variable name - construct from $name
  $dirvarname = substr($name,0,1).substr($name,-1)."_direction";
  FrmSelectEasy($dirvarname, array( '0'=>_m("Ascending"), '1' => _m("Descending"), '2' => _m("Ascending by Priority"), '3' => _m("Descending by Priority") ), 
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
  go_url( $sess->url(self_base() . "se_views.php3"));

if(!IfSlPerm(PS_FULLTEXT)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You do not have permission to change views"), "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$p_slice_id = q_pack_id($slice_id);

$VIEW_FIELDS = getViewFields();
$VIEW_TYPES = getViewTypes();
$VIEW_TYPES_INFO = getViewTypesInfo();

if( $update )
{
  do
  {
    reset($VIEW_FIELDS);
    while(list($k, $v) = each($VIEW_FIELDS)) {
      if( $v["validate"] AND $VIEW_TYPES[$view_type][$k] )
        ValidateInput($k, $VIEW_TYPES[$view_type][$k], $$k, $err, false, $v["validate"]);
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
        $err["DB"] = MsgErr( _m("Can't change slice settings") );
        break;   # not necessary - we have set the halt_on_error
      }
    } else {  
      if( !$db->query("INSERT INTO view ". $varset->makeINSERT())) {
        $err["DB"] = MsgErr( _m("Can't insert into view.") );
        break;   # not necessary - we have set the halt_on_error
      }
    }
    $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
    $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values

    reset($VIEW_TYPES[$view_type]);
    while(list($k, $v) = each($VIEW_TYPES[$view_type])) 
      if (substr ($k,0,strlen("function:")) == "function:") {
        $show_fn = "store_".substr($k,strlen("function:"));
        $show_fn ();
      }    

    go_url( $sess->url(self_base() . "se_views.php3"));
  }while(false);

  if( count($err) <= 1 )
    $Msg = MsgOK(_m("View successfully changed"));
}

if( !$update ) {  # set variables from database
  if( $view_id )  # edit specified view data
    $SQL= " SELECT * FROM view WHERE id='$view_id'";
  elseif( $new_templ AND $view_view)   # new view from template
    $SQL= " SELECT * FROM view WHERE id='$view_view'";
  elseif( $view_type )         # new view - get default values from view table - 
                               #            take first view of the same type
    $SQL= " SELECT * FROM view WHERE type='$view_type' ORDER by id";
  else         # error - someone swith the slice or so
    go_url($sess->url("se_views.php3")); 

  $db->query($SQL);
  
  if ($db->next_record()) {
    $vw_data = $db->Record;
    if( $new_templ )           # if we create view from template - get view type
      $view_type = $db->f(type);  
  } else
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
                    "NOTNULL"  => "is set",
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
echo "<TITLE>". _m("Admin - design View") ."</TITLE>
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
require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
showMenu ($aamenus, "sliceadmin","");

echo "<H1><B>" . _m("Admin - design View") . "</B></H1>";
PrintArray($err);
echo $Msg;

?>
<form name=f enctype='multipart/form-data' method=post action='<?php echo $sess->url($PHP_SELF) ?>'>
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Defined Views")?></b><BR>
</td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php

FrmStaticText(_m("Id"), $view_id );

echo "<input type=hidden name='view_type' value='$view_type'>";

reset($VIEW_TYPES[$view_type]);
while(list($k, $v) = each($VIEW_TYPES[$view_type])) {
  if (substr ($k,0,strlen("function:")) == "function:") {
    $show_fn = "show_".substr($k,strlen("function:"));
    $show_fn ();
  }    
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
  case 'discus2mail': PrintAliasHelp(GetDiscussion2MailAliases());
  case 'discus': PrintAliasHelp(GetDiscussionAliases());
                 break;
  case 'field' :  if( $r_fields )
                    $fields = $r_fields;
                  else
                    list($fields,) = GetSliceFields($slice_id);
                  PrintAliasHelp(GetAliasesFromFields($fields, $VIEW_TYPES_INFO[$view_type]['aliases_additional']));
                  break;
  case 'const': PrintAliasHelp(GetConstantAliases());
                break;
  case 'none':  break;
}                

echo "<tr><td align='center'>
      <input type=hidden name=view_id value='$view_id'>
      <input type=submit name=update value='". _m("Update") ."'>&nbsp;&nbsp;<input
             type=submit name=cancel value='". _m("Cancel") ."'></td></tr></table>
    </FORM><br>";
    
if( $view_id ) {
  $ssiuri = ereg_replace("/admin/.*", "/view.php3", $PHP_SELF);
  echo _m("<br>To include slice in your webpage type next line \n                         to your shtml code: ") ."<br><pre>&lt;!--#include virtual=&quot;" . $ssiuri . 
       "?vid=$view_id&quot;--&gt;</pre>";
}    
HtmlPageEnd();
page_close();
?>