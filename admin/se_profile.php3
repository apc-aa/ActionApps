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

// expected $uid - user id we have to edit profile for

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";
require_once AA_INC_PATH."profile.php3";
require_once AA_INC_PATH."constants_param_wizard.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "./se_users.php3"));
}

if (!IfSlPerm(PS_USERS)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to manage users"), "admin");
    exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

if ( $del ) {
    // slice identification is not neccessry
    $SQL = "DELETE FROM profile WHERE id='$del' AND slice_id='$p_slice_id'";
    if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
        $err["DB"] = MsgErr("Can't delete profile");
        exit;
    }

    $Msg = MsgOK(_m("Rule deleted"));
}

if ( $add ) {
    if (!AddProfileProperty($uid, $slice_id, $property, $field_id, $fnction, $param, $html)) {
        $Msg = MsgOK(_m("Error: Can't add rule"));
    }
}

// prepare forms ---------------------------------------------------------------

// get current profiles
$SQL= "SELECT * FROM profile WHERE slice_id='$p_slice_id' AND (uid='$uid') ORDER BY property, selector";
$db->query($SQL);
while ($db->next_record()) {
    $rules[] = $db->Record;
}

// get fields for this slice
list($fields,) = GetSliceFields($slice_id);
foreach ($fields as $k => $v) {
    $lookup_fields[$k] = $v['name'];
}

// set property names array
$PROPERTY_TYPES = array( 'listlen'     =>_m("Item number"),
                         'input_view'  =>_m("Input view ID"),
                         'admin_search'=>_m("Item filter"),
                         'admin_order' =>_m("Item order"),
                         'hide'        =>_m("Hide field"),
                         'hide&fill'   =>_m("Hide and Fill"),
                         'fill'        =>_m("Fill field"),
                         'predefine'   =>_m("Predefine field"),
                         'bookmark'    =>_m("Stored query")
                       );

$SORTORDER_TYPES = array( '+'=>_m("Ascending"), '-' => _m("Descending") );

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Admin - user Profiles");?></TITLE>
<script language="JavaScript"><!--
  function addrule(n) {
    var si;
    document.sf.property.value = eval('document.fr.prop'+n+'.value');
    if ( eval('document.fr.param'+n) != null )
      document.sf.param.value = eval('document.fr.param'+n+'.value');
    if ( eval('document.fr.html'+n) != null )
      document.sf.html.value = ((eval('document.fr.html'+n).checked) ? '1' : '0');
    if ( eval('document.fr.fnc'+n) != null ) {
      si = eval('document.fr.fnc'+n+'.options.selectedIndex');
      document.sf.fnction.value = eval('document.fr.fnc'+n+'.options['+si+'].value');
    }
    if ( eval('document.fr.fld'+n) != null ) {
      si = eval('document.fr.fld'+n+'.options.selectedIndex');
      document.sf.field_id.value = eval('document.fr.fld'+n+'.options['+si+'].value');
    }
    document.sf.submit();
  }
//-->
</script>
</HEAD>
<?php
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "sliceadmin","");

echo "<H1><B>" . _m("Admin - user Profiles") . "</B></H1>";
PrintArray($err);
echo $Msg;

echo "
 <table width=\"70%\" border=\"0\" cellspacing=\"0\" cellpadding=\"1\" bgcolor=\"". COLOR_TABTITBG ."\" align=\"center\">
  <tr>
   <td class=tabtit><b>&nbsp;". _m("Rules") ." - $uid</b></td>
  </tr>
  <tr>
   <td>
    <table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"". COLOR_TABBG ."\">";

if ( isset($rules) AND is_array($rules) ) {
    foreach ($rules as $v) {
        PrintRule($v);
    }
} else {
    echo "<tr><td>"._m("No rule is set")."</td></tr>";
}

echo "</table>
  <tr>
   <td class=tabtit><b>&nbsp;". _m("Add Rule") ."</b></td>
  </tr>
  <tr>
   <td>
    <form name=fr>
     <table border=\"0\" cellspacing=\"0\" cellpadding=\"4\" width=\"100%\" bgcolor=\"". COLOR_TABBG ."\">
      <tr class=tabtxt align=center>
       <td><b>". _m("Rule") . "</b></td>
       <td><b>". _m("Field") . "</b></td>
       <td><b>". _m("Function") . "</b></td>
       <td><b>". _m("Value") . "</b></td>
       <td><b>". _m("HTML") . "</b></td>
       <td>&nbsp;</td>
      </tr>";

$inputDefaultTypes = getSelectBoxFromParamWizard($DEFAULT_VALUE_TYPES);


// row, rule, show_field_selectbox, function_selectbox, show_parameter_box, show_html_checkbox, description
PrintSetRule(1,'listlen',     0,0,                  1,0,_m("number of item displayed in Item Manager") );
PrintSetRule(2,'input_view',  0,0,                  1,0,_m("id of view used for item input") );
PrintSetRule(3,'admin_search',1,$inputDefaultTypes, 1,0,_m("preset \"Search\" in Item Manager"));
PrintSetRule(4,'admin_order', 1,$SORTORDER_TYPES,   0,0,_m("preset \"Order\" in Item Manager"));
PrintSetRule(5,'hide',        1,0,                  0,0,_m("hide the field in inputform"));
PrintSetRule(6,'hide&fill',   1,$inputDefaultTypes, 1,1,_m("hide the field in inputform and fill it by the value"));
PrintSetRule(7,'fill',        1,$inputDefaultTypes, 1,1,_m("fill the field in inputform by the value"));
PrintSetRule(8,'predefine',   1,$inputDefaultTypes, 1,1,_m("predefine value of the field in inputform"));

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