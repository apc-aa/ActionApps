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

# expected $uid - user id we have to edit profile for

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

function InsertProfileProperty($uid, $property, $selector, $value) {
  global $db, $p_slice_id, $err;
  $SQL = "INSERT INTO profile SET slice_id='$p_slice_id',
                                  uid = '$uid',
                                  property = '$property',
                                  selector = '$selector',
                                  value = '$value'";
  if (!$db->query($SQL)) 
    $err["DB"] = MsgErr("Can't update profile");
}

function DeleteProfileProperty($property, $selector="") {
  global $db, $p_slice_id, $err;
  # first delete the records in order we can add new
  if( $selector )
    $add = " AND selector = '$selector' ";

  $SQL = "DELETE FROM profile WHERE property='$property'
                                AND slice_id='$p_slice_id' $add";
  if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
    $err["DB"] = MsgErr("Can't delete profile");
    break;
  }
}

function PrintRuleRow($rid, $prop, $col1="", $col2="", $col3="", $col4="") {
  global $sess, $uid;
  echo "<tr class='tabtxt'>
          <td>$prop&nbsp;</td>
          <td>$col1&nbsp;</td>
          <td>$col2&nbsp;</td>
          <td>$col3&nbsp;</td>
          <td>$col4&nbsp;</td>
          <td align='right'><a href=\"javascript:document.location='". 
            $sess->url("se_profile.php3?del=$rid&uid=$uid"). "'\">". L_DELETE ."</a></td>
        </tr>";  
}          

function PrintRule($rule) {
  global $PROPERTY_TYPES, $SORTORDER_TYPES, $INPUT_DEFAULT_TYPES, $fields;
  
  $prop = $rule['property'];
  $rid  = $rule['id'];

  switch($prop) {
    case 'listlen': 
      PrintRuleRow($rid, $PROPERTY_TYPES[$prop], "", $rule['value']); 
      break;
    case 'admin_order': 
      $fid = substr( $rule['value'], 0, -1 );
      $ord = substr( $rule['value'], -1 );
      PrintRuleRow($rid, $PROPERTY_TYPES[$prop], $fields[$fid]['name'], $SORTORDER_TYPES[$ord]); 
      break;
    case 'admin_search':
      $pos = strpos($rule['value'],':');
      $fid = substr($rule['value'], 0, $pos);
      $val = substr($rule['value'], $pos+1);
      PrintRuleRow($rid, $PROPERTY_TYPES[$prop], $fields[$fid]['name'], $val); 
      break;
    case 'hide':
      PrintRuleRow($rid, $PROPERTY_TYPES[$prop], $fields[$rule['selecor']]['name']); 
      break;
    case 'fill':
    case 'hide&fill':
    case 'predefine':
      $fnc = ParseFnc(substr($rule['value'],2));  # all default should have fnc:param format
      PrintRuleRow($rid, $PROPERTY_TYPES[$prop], $fields[$rule['selector']]['name'],
                   $INPUT_DEFAULT_TYPES[$fnc['fnc']], $fnc['param'], 
                   ($rule['value'][0] == '1')? 'HTML' : "" ); 
      break;
  }
}

function PrintSetRule($n, $rule, $sfld, $func, $sparam, $shtml, $desc) {
  global $PROPERTY_TYPES, $lookup_fields, $SORTORDER_TYPES, $INPUT_DEFAULT_TYPES, $fields;
  echo "<tr class=tabtxt>
         <td>". $PROPERTY_TYPES[$rule]. "<input type=hidden name=prop$n value=$rule></td>
         <td>"; 
  if($sfld)
    FrmSelectEasy("fld$n", $lookup_fields, "");
   else 
    echo "&nbsp;";
  echo " </td>
         <td>";
  if($func)
    FrmSelectEasy("fnc$n", $func, "");
   else 
    echo "&nbsp;";
  echo " </td>
         <td>". ($sparam ? "<input type=text name=param$n size=20>" : "&nbsp;"). "</td>
         <td>". ($shtml  ? "<input type=checkbox name=html$n>" : "&nbsp;"). "</td>
         <td><a href=\"javascript:addrule($n)\">". L_ADD ."</a></td></tr>";
}


# -------- end functions definition -------------------------------------------

if($cancel)
  go_url( $sess->url(self_base() . "./se_users.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_USERS)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_USERS, "admin");
  exit;
}  

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

if( $del ) {
  $SQL = "DELETE FROM profile WHERE id='$del' 
             AND slice_id='$p_slice_id'"; #slice identification is not neccessry 
  if (!$db->query($SQL)) {  # not necessary - we have set the halt_on_error
    $err["DB"] = MsgErr("Can't delete profile");
    break;
  }

  $Msg = MsgOK(L_PROFILE_DELETE_OK);
}

if( $add ) {
  do {
    switch($property) {
      case 'listlen':
        if( $param > 0 ) {
          DeleteProfileProperty($property);
          InsertProfileProperty($uid, $property, '0', $param);
          $Msg = MsgOK(L_PROFILE_ADD_OK);
        }
        break;  
      case 'admin_order':
        if( $field_id ) {
          DeleteProfileProperty($property);
          InsertProfileProperty($uid, $property, '0', $field_id.$funct);
          $Msg = MsgOK(L_PROFILE_ADD_OK);
        }
        break;  
      case 'admin_search':
        if( $field_id ) {
          DeleteProfileProperty($property);
          InsertProfileProperty($uid, $property, '0', "$field_id:$param");
          $Msg = MsgOK(L_PROFILE_ADD_OK);
        }
        break;  
      case 'hide':
        if( $field_id ) {
          DeleteProfileProperty($property, $field_id);
          InsertProfileProperty($uid, $property, $field_id, '1');
          $Msg = MsgOK(L_PROFILE_ADD_OK);
        }
        break;  
      case 'fill':
      case 'hide&fill':
      case 'predefine':
        if( $field_id ) {
          DeleteProfileProperty($property,$field_id);
          InsertProfileProperty($uid, $property, $field_id, "$html:$fnction:$param");
          $Msg = MsgOK(L_PROFILE_ADD_OK);
        }
        break;  
   }    
  } while( 0 );           #in order we can use "break;" statement
  if( count($err) > 1)
    $Msg = MsgOK(L_PROFILE_ADD_ERR);
}

# prepare forms ---------------------------------------------------------------

# get current profiles
$SQL= "SELECT * FROM profile WHERE slice_id='$p_slice_id' AND (uid='$uid')
        ORDER BY property, selector";
$db->query($SQL);
while($db->next_record())
  $rules[] = $db->Record;

# get fields for this slice
list($fields,) = GetSliceFields($slice_id);
reset( $fields );
while( list($k, $v) = each($fields) )
  $lookup_fields[$k] = $v[name];

  
# set property names array
$PROPERTY_TYPES = array( 'listlen'=>L_PROFILE_LISTLEN,
                         'admin_search'=>L_PROFILE_ADMIN_SEARCH,
                         'admin_order'=>L_PROFILE_ADMIN_ORDER,
                         'hide'=>L_PROFILE_HIDE,
                         'hide&fill'=>L_PROFILE_HIDEFILL,
                         'fill'=>L_PROFILE_FILL,
                         'predefine'=>L_PROFILE_PREDEFINE);

$SORTORDER_TYPES = array( '+'=>L_ASCENDING, '-' => L_DESCENDING );
                         
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_PROFILE_TIT;?></TITLE>
<script language="JavaScript"><!--
  function addrule(n) {
    var si;
//    alert( eval('document.fr.prop'+n ).value );
//    alert( eval('document.fr.prop'+n+'.value'));
    document.sf.property.value = eval('document.fr.prop'+n+'.value');
    if( eval('document.fr.param'+n) != null )
      document.sf.param.value = eval('document.fr.param'+n+'.value');
    if( eval('document.fr.html'+n) != null )
      document.sf.html.value = ((eval('document.fr.html'+n).checked) ? '1' : '0');
    if( eval('document.fr.fnc'+n) != null ) {
      si = eval('document.fr.fnc'+n+'.options.selectedIndex');
      document.sf.fnction.value = eval('document.fr.fnc'+n+'.options['+si+'].value');
    }  
    if( eval('document.fr.fld'+n) != null ) {
      si = eval('document.fr.fld'+n+'.options.selectedIndex');
      alert( si );
      document.sf.field_id.value = eval('document.fr.fld'+n+'.options['+si+'].value');
    }  
    document.sf.submit();
  }
//-->
</script>
</HEAD>
<?php 
require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

echo "<H1><B>" . L_A_PROFILE_TIT . "</B></H1>";
PrintArray($err);
echo $Msg;  

echo "
 <table width=\"70%\" border=\"0\" cellspacing=\"0\" cellpadding=\"1\" bgcolor=\"". COLOR_TABTITBG ."\" align=\"center\">
  <tr>
   <td class=tabtit><b>&nbsp;". L_PROFILE_HDR ."</b></td>
  </tr>
  <tr>
   <td>
    <table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">";
    
if( isset($rules) AND is_array($rules) ) {
  reset($rules);
  while( list(,$v) = each($rules))
    PrintRule($v);
} else
  echo "<tr><td>".L_NO_RULE_SET."</td></tr>";

echo "</table>  
  <tr>
   <td class=tabtit><b>&nbsp;". L_PROFILE_ADD_HDR ."</b></td>
  </tr>
  <tr>
   <td>
    <form name=fr>
     <table border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">";

PrintSetRule(1,'listlen',     0,0,                   1,0,L_PROFILE_LISTLEN_DESC );
PrintSetRule(2,'admin_search',1,0,                   1,0,L_PROFILE_ADMIN_SEARCH_DESC);
PrintSetRule(3,'admin_order', 1,$SORTORDER_TYPES,    0,0,L_PROFILE_ADMIN_ORDER_DESC);
PrintSetRule(4,'hide',        1,0,                   0,0,L_PROFILE_HIDE_DESC);
PrintSetRule(5,'hide&fill',   1,$INPUT_DEFAULT_TYPES,1,1,L_PROFILE_HIDEFILL_DESC);
PrintSetRule(6,'fill',        1,$INPUT_DEFAULT_TYPES,1,1,L_PROFILE_FILL_DESC);
PrintSetRule(7,'predefine',   1,$INPUT_DEFAULT_TYPES,1,1,L_PROFILE_PREDEFINE_DESC);

echo "</table>
    </form>
    <form name=sf action='se_profile.php3'>
      <input type='hidden' name='uid' value='$uid'>
      <input type='hidden' name='add' value='1'>
      <input type='hidden' name='property'>
      <input type='hidden' name='param'>
      <input type='hidden' name='field_id'>
      <input type='hidden' name='fnction'>
      <input type='hidden' name='html'>";
      $sess->hidden_session();
echo "</form>
    </td>
   </tr>
  </table>
</body>
</html>";

/*
$Log$
Revision 1.1  2001/12/18 12:12:27  honzam
new user profile feature

Revision 1.16  2001/10/24 18:44:11  honzam
new parameter wizard for function aliases and input type parameters

Revision 1.15  2001/09/27 16:07:39  honzam
New related stories support, New constant view

Revision 1.14  2001/08/03 10:09:30  honzam
if no time in expiry date is specified, the end of day is stored

Revision 1.13  2001/06/21 14:15:45  honzam
feeding improved - field value redefine possibility in se_mapping.php3

Revision 1.12  2001/06/12 16:00:54  honzam
date inputs support time, now
new multivalue input possibility - <select multiple>

Revision 1.11  2001/06/03 15:57:45  honzam
multiple categories (multiple values at all) for item now works

Revision 1.10  2001/05/21 13:52:31  honzam
New "Field mapping" feature for internal slice to slice feeding

Revision 1.9  2001/05/18 13:50:09  honzam
better Message Page handling (not so much)

Revision 1.8  2001/04/09 20:43:28  honzam
fixed bug of not stored parameter for field input type in se_inputform.php3

Revision 1.6  2001/03/20 15:27:03  honzam
Changes due to "slice delete" feature

Revision 1.5  2001/02/26 17:26:08  honzam
color profiles

Revision 1.4  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.2  2001/01/08 13:31:58  honzam
Small bugfixes

Revision 1.1.1.1  2000/06/21 18:39:59  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:49  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.12  2000/06/12 19:58:24  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.11  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.10  2000/04/24 16:45:02  honzama
New usermanagement interface.

Revision 1.9  2000/03/22 09:36:43  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc

*/

page_close()?>