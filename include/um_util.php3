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

if (!defined("AA_UM_UTIL_INCLUDED"))
     define ("AA_UM_UTIL_INCLUDED",1);
else return;

# Prints html tag <select ..
function SelectGU_ID($name, $arr, $selected="", $type="short", $substract="") {
  if( $substract=="" )                 // $substract list of values not shovn in <select> even if in $arr
    $substract = array();
  if( $type == "short" )               // 1-row listbox
    echo "<select name=\"$name\">";
   else                                // 8-row listbox
    echo "<select name=\"$name\" size=8>";
  if( isset($arr) AND is_array($arr)) {
    reset($arr);
    while(list($k, $v) = each($arr)) {
      if( ($v[name] != "") AND ($substract[$k] == "") ) {
        $option_exist = true;
        echo "<option value=\"". htmlspecialchars($k)."\"";
        if ((string)$selected == (string)$k)
          echo " selected";
        echo "> ". htmlspecialchars($v[name]) ." </option>";
      }
    }
    if( !$option_exist )  // if no options, we must set width of <select> box
      echo '<option value="wIdThTor"> &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; </option>';
  }
  echo "</select>\n";
}

function GetFiltered($type, $filter, $to_much, $none) {
  switch( $type ) {
    case "U": $list = FindUsers($filter); break;
    case "G": $list = FindGroups($filter); break;
  }
  if( !is_array($list) ) {
    unset($list);
    $list["n"][name] = (( $list == "too much" ) ? $to_much : $none);
  }
  //p_arr_m($list);
  return $list;
}

function PrintModulePermModificator($selected_user) {
  global $db;
  ?>

  <tr><td class=tabtit><b>&nbsp;<?php echo _m("Permissions")?></b></td></tr>
  <tr><td>
  <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
  <?php
  echo '<tr>
          <td>'. _m("Object") .'</td>
          <td>'. _m("Permissions") .'</td>
          <td>'. _m("Revoke") .'</td></tr>';

  $perm_slices = GetIDPerms($selected_user, "slice", 1);  # there are not only Slices, but other Modules too
  $SQL = "SELECT name, type, id FROM module ORDER BY type,name";
  $db->query($SQL);
  while( $db->next_record() ) {
    $mid = unpack_id($db->f('id'));
    if( $perm_slices[$mid] )
      PrintModulePermRow($mid, $db->f('type'), $db->f('name'), $perm_slices[$mid]);
     else {               # no permission to this module
                          # this module should be listed in 'Add perm' listbox
      $mod_2B_add .= "<option value=\"$mid\">". safe($db->f('name')) .'</option>';
      $mod_types .= $db->f('type');    # string for javascript to know, what
    }                                  # type of module the $mod_2B_add is
  }?>
    </table></td></tr>
   <tr><td class=tabtit><b>&nbsp;<?php echo _m("Assign new permissions")?></b></td></tr>
   <tr><td>
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
      <?php
  if( isset($mod_2B_add) ) {          # there is some module to add
    PrintModuleAddRow($mod_2B_add, 1);
    PrintModuleAddRow($mod_2B_add, 2);
    PrintModuleAddRow($mod_2B_add, 3);
  } ?>
    </table></td></tr><?php
  return $mod_types;
}


function PrintModulePermRow($mid, $type, $name, $perm) {
  global $MODULES, $perms_roles_modules, $perms_roles;
  echo "<tr>
         <td align='top'>".$MODULES[$type]['name'] .":&nbsp;$name<br>&nbsp;&nbsp;&nbsp;&nbsp;($mid)</td>
         <td nowrap align='top'>";
  if( isset($perms_roles_modules[$type]) AND is_array($perms_roles_modules[$type]) ) {
    reset($perms_roles_modules[$type]);
    while( list( ,$role) = each( $perms_roles_modules[$type] ) ) {
      echo "<input type=\"radio\" name=\"perm_mod[x$mid]\" value=\"$role\"";
      echo ( ComparePerms($perm,$perms_roles[$role]['id'])=='E' ) ?
                                                             ' checked>' : '>';
      echo "$role ";
    }
  } else {
    echo "<input type=\"radio\" name=\"perm_mod[x$mid]\" value=\"ADMINISTRATOR\"
          checked>ADMINISTRATOR";
  }
  echo "  </td>
          <td nowrap align='top'>
            <input type=\"radio\" name=\"perm_mod[x$mid]\" value=\"REVOKE\">". _m("Revoke") ."</td>
        </tr>";
}


function PrintModuleAddRow($mod_options, $no) {
  echo "<tr>
         <td><select name=\"new_module[$no]\" onchange=\"SetRole($no)\">
               <option> </option>
               $mod_options</select></td>
         <td><select name=\"new_module_role[$no]\">
               <option> </option></select></td>
        </tr>";
}


# Change module permissions if user wants
# Works not only with users, but with groups too
function ChangeUserModulePerms( $perm_mod, $selected_user, $perms_roles ) {
  if ($debug) {
    echo "<br>function ChangeUserModulePerms( $perm_mod, $selected_user, $perms_roles )";
    print_r($perm_mod);
    print_r($perms_roles);
  }
  if( isset($perm_mod) AND is_array($perm_mod) ) {
    $perm_slices = GetIDPerms($selected_user, "slice", 1);  # there are not only Slices, but other Modules too
    reset($perm_mod);
    while( list($xmid,$role) = each($perm_mod) ) {
      $mid=substr($xmid,1);   # removes first 'x' character (makes index string)
      if( $role == 'REVOKE' )
        DelPerm($selected_user, $mid, 'slice');
      elseif( ComparePerms($perm_slices[$mid], $perms_roles[$role]['id']) != 'E' )
        ChangePerm($selected_user, $mid, 'slice', $perms_roles[$role]['id']);
    }
  }
}

# Add new modules for this user
# Works not only with users, but with groups too
function AddUserModulePerms( $new_module, $new_module_role, $selected_user, $perms_roles) {
  if( isset($new_module) AND is_array($new_module) ) {
    reset($new_module);
    while( list($no,$mid) = each($new_module) ) {
      if( (trim($mid) != "") AND isset($perms_roles[$new_module_role[$no]]) )
        AddPerm($selected_user, $mid, 'slice', $perms_roles[$new_module_role[$no]]['id']);
    }
  }
}

function PrintPermUmPageEnd($MODULES, $mod_types, $perms_roles_modules) { ?>
  <script language="JavaScript"><!--
    var mod = new Array();
    <?php
      # tell javascript, which module uses which permission roles
      echo "\n var mod_types='$mod_types';\n";
      reset($MODULES);
      while( list($k,$v) = each($MODULES) )
        echo " mod[".ord($k)."] = new Array('". join("','", $perms_roles_modules[$k]) ."');  // module type $k\n";
    ?>
    // set right roles for modules listed in 'Add rows'
    SetRole(1);
    SetRole(2);
    SetRole(3);
    // -->
  </script>
  <?php
}

?>
