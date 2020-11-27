<?php
/**
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/

bind_mgettext_domain(DEFAULT_LANG_INCLUDE);
require_once __DIR__."/util.php3";

HtmlPageBegin();

echo '<title>ActionApps - '.ORG_NAME.'</title>';
?>
</head>
<body bgcolor="<?php echo COLOR_TABTITBG ?>" link="#D20000" vlink="#D20000" style="text-align: center;">
<h1><?php echo _m("Welcome!") ?></h1>

<?php echo _m("Welcome! Please identify yourself with a username and a password:") ?>
<?php
   $qs= str_replace( ["&relogin=true", "relogin=true"],"", $this->url());
?>
<form action="<?php echo $qs ?>" method=post>
<table border="0" bgcolor="<?php echo COLOR_TABBG ?>" cellspacing=0 cellpadding=4 style="margin: 0px auto;">

 <tr valign="top" align="left">
  <td><?php echo _m("Username:") ?></td>
  <td>
      <input type="text" name="username" value="<?php if (isset($this->auth["uname"])) { myspecialchars(print $this->auth["uname"]); }?>" size="32" maxlength="60" autocomplete="username">
      <br><small><?php echo _m("Type your username or mail") ?></small>
  </td>
 </tr>

 <tr valign="top" align="left">
  <td><?php echo _m("Password:") ?></td>
  <td><input type="password" name="password" size="32" maxlength="255" autocomplete="current-password"></td>
 </tr>
    <?php
    if (AA_Auth::use2FA()) {
        echo ' <tr valign="top" align="left">
          <td>'. _m("One Time Code") .'</td>
          <td><input type="text" name="code2fa" size="32" maxlength="255"  inputmode="numeric"  pattern="[0-9]*" autocomplete="one-time-code"></td>
         </tr>';
    }
    ?>

 <tr>
  <td colspan="2" align="center"><input type="submit" name="submit" value="<?php echo _m("Login now") ?>"></td>
 </tr>
</table>

 <br><br>

<table style="margin: 0px auto;">
  <tr align="center">
   <td><?php echo $login_msg; ?></td>
  </tr>
</table>

</form>
<script>
  if (document.forms[0][0].value != '') {
      document.forms[0][2].focus();
  } else {
      document.forms[0][0].focus();
  }
</script>
</body>
</html>
