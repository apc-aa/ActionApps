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

require_once __DIR__ . "/util.php";

HtmlPageBegin();

echo '<title>ActionApps - '.ORG_NAME.'</title>';
?>
</head>
<body style="text-align: center;">
<h1><?php echo _m("Welcome!") ?></h1>
<?php // styles to match the old login form ?>
<style>
    body {
        background: #f5f0e7;
        font-size: 13px;
        line-height: inherit;
    }
    body * {
        font-family: Verdana, Arial, Helvetica, sans-serif;
    }
    h1 {
        font-weight: bold;
        font-size: 16px;
        margin-top: 12px;
    }
    .aa-login-form {
        background: #EBDABE;
        margin: 0 auto;
        display: inline-block;
        width: inherit;
        padding: 5px;
    }
    .aa-login-row {
        display: flex;
        margin-top: 0;
    }
    .aa-login-row:last-child {
        display: block !important;
        margin: 0 auto;
    }
    .aa-login-row label {
        font-weight: normal;
        width: 135px;
        text-align: left;
        padding-left: 2px;
        vertical-align: top;
    }
    .aa-login-row label strong {
        font-weight: normal;
        display:block;
    }
    .aa-login-row input {
        text-align: left;
        padding: 0 3px;
        margin: 3px 2px 15px;
    }

    input[type="submit"].btn-l {
        display: inline-block;
        background-color: #D5C2A4;
        padding: 5px 15px;
        border: none;
        margin: 2px;
        text-decoration: none;
        color: black;
        font-size: 11px;
        font-family: arial, sans-serif;
    }
    .aa-login-submit {
        display: block;
        width: 100%;
    }
</style>

<div class="aa-login-welcome">
<?php
  echo _m("Welcome! Please identify yourself with a username and a password:");
  $qs= str_replace( ["&relogin=true", "relogin=true"],"", $this->url());
?>
</div>
<form action="<?php echo $qs ?>" method=post class="aa-login-form">

    <div class="aa-login-row">
      <label for="username"><?php echo _m("Username:") ?></label>
      <input type="text" name="username" value="<?php if (isset($this->auth["uname"])) { safe(print $this->auth["uname"]); }?>" size="32" maxlength="255" autocomplete="username">
    </div>
    <div class="aa-login-row">
      <label for="password"><?php echo _m("Password:") ?></label>
      <input type="password" name="password" size="32" maxlength="255" autocomplete="current-password">
    </div>
    <?php
    if (AA_Auth::use2FA()) {
        echo '
        <div class="aa-login-row">
            <label for="password">'. _m("One Time Code") .'</label>
            <input type="text" name="code2fa" size="32" maxlength="255"  inputmode="numeric"  pattern="[0-9]*" autocomplete="one-time-code">
        </div>';
    }
    ?>
   <div class="aa-login-submit">
        <input type="submit" value="<?php echo _m("Login now") ?>" class="btn-l">
    </div>

</form>

<div class="aa-login-msg mt-2" style="text-align: center;">
    <?php echo $login_msg; ?>
</div>

<script>
  if (document.forms[0][0].value != '') {
      document.forms[0][2].focus();
  } else {
      document.forms[0][0].focus();
  }
</script>
</body>
</html>
