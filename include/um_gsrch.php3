<?php  //um_gsrch.php3  - include file with user search form
/**
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
  $groups = AA::$perm->findGroups($grp);
  if ( !is_array($groups) ) {
    if ( $groups == "too much" ) {
      unset($groups);
      $groups[0] = _m("Too many users or groups found.");
    } else {
      unset($groups);
      $groups[0] = _m("No user (group) found");
    }
  }

?>
<form method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
 <table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
  <tr><td class="tabtit"><b>&nbsp;<?php echo _m("Groups")?></b></td></tr>
  <tr><td>
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
     <tr>
        <td>&nbsp;</td>
        <td><input type="text" name="grp" value="<?php echo safe($grp)?>"></td>
        <td><input type="submit" name="GrpSrch" value="<?php echo _m("Search")?>"></td>
     </tr>
     <tr>
        <td class="tabtxt"><b><?php echo _m("Group") ?></b></td>
        <td><?php SelectGU_ID("selected_group", $groups, $selected_group) ?></td>
        <td><input type="submit" name="grp_edit" value="<?php echo _m("Edit")?>">&nbsp;
          <input type="submit" name="grp_del" value="<?php echo _m("Delete")?>">
          <input type="hidden" name="usr" value="<?php echo safe($usr)?>"></td>
     </tr>
    </table>
   </td>
  </tr>
 </table>
</form>

