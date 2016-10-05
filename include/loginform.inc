<?php
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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

bind_mgettext_domain(AA_INC_PATH."lang/".DEFAULT_LANG_INCLUDE);
require_once AA_INC_PATH."util.php3";

HtmlPageBegin();

echo '<title>ActionApps - '.ORG_NAME.'</title>';
?>
</head>
<body bgcolor="<?php echo COLOR_TABTITBG ?>" link="#D20000" vlink="#D20000">
<center>
<h1><?php echo _m("Welcome!") ?></h1>

<?php echo _m("Welcome! Please identify yourself with a username and a password:") ?>
</center>
<?php
   $qs= str_replace( array("&relogin=true", "relogin=true"),"", $this->url());
?>
<form action="<?php echo $qs ?>" method=post>
<center><table border="0" bgcolor="<?php echo COLOR_TABBG ?>" cellspacing=0 cellpadding=4>
 <tr valign="top" align="left">
  <td><?php echo _m("Username:") ?></td>
  <td><input type="text" name="username"
  value="<?php if (isset($this->auth["uname"])) { myspecialchars(print $this->auth["uname"]); }?>"
  size="16" maxlength="32"><br><small><?php echo _m("Type your username or mail") ?></small></td>
 </tr>
 <tr valign="top" align="left">
  <td><?php echo _m("Password:") ?></td>
  <td><input type="password" name="password" size="16" maxlength="32"></td>
 </tr>
 <tr>
  <td colspan="2" align="center"><input type="submit" name="submit" value="<?php echo _m("Login now") ?>"></td>
 </tr>
</table>

 <br><br>

<table>
  <tr align="center">
   <td><?php echo $login_msg; ?></td>
  </tr>
</table>

</center>
</form>
<script type="text/javascript">
<!--
  if (document.forms[0][0].value != '') {
      document.forms[0][2].focus();
  } else {
      document.forms[0][0].focus();
  }
// -->
</script>
</body>
</html>
