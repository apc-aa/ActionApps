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
require $GLOBALS[AA_INC_PATH]."msgpage.php3";
require $GLOBALS[AA_INC_PATH]."profile.php3";

if($cancel)
  go_url( $sess->url(self_base() . "./se_users.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_USERS)) {
  MsgPageMenu($sess->url(self_base())."index.php3", L_NO_PS_USERS, "admin");
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
          InsertProfileProperty($uid, $property, '0', $field_id.$fnction);
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
//      alert( si );
      document.sf.field_id.value = eval('document.fr.fld'+n+'.options['+si+'].value');
    }
    document.sf.submit();
  }
//-->
</script>
</HEAD>
<?php
require $GLOBALS[AA_INC_PATH]."menu.php3";
showMenu ($aamenus, "sliceadmin","");

echo "<H1><B>" . L_A_PROFILE_TIT . "</B></H1>";
PrintArray($err);
echo $Msg;

echo "
 <table width=\"70%\" border=\"0\" cellspacing=\"0\" cellpadding=\"1\" bgcolor=\"". COLOR_TABTITBG ."\" align=\"center\">
  <tr>
   <td class=tabtit><b>&nbsp;". L_PROFILE_HDR ." - $uid</b></td>
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
     <table border=\"0\" cellspacing=\"0\" cellpadding=\"4\" width=\"100%\" bgcolor=\"". COLOR_TABBG ."\">
      <tr class=tabtxt align=center>
       <td><b>". L_RULE . "</b></td>
       <td><b>". L_FIELD . "</b></td>
       <td><b>". L_FUNCTION . "</b></td>
       <td><b>". L_VALUE . "</b></td>
       <td><b>". L_HTML . "</b></td>
       <td>&nbsp;</td>
      </tr>";

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
  </table>";
HTMLPageEnd();
page_close()?>