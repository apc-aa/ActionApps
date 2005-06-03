<?php
bind_mgettext_domain ($GLOBALS["AA_INC_PATH"]."lang/".DEFAULT_LANG_INCLUDE);
require_once $GLOBALS["AA_INC_PATH"]."util.php3";

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
 $qs= eregi_replace( "\&relogin=true","",$this->url());
 $qs= eregi_replace( "relogin=true","",$qs);
?>
<form action="<?php echo $qs ?>" method=post>
<center><table border=0 bgcolor="<?php echo COLOR_TABBG ?>" cellspacing=0 cellpadding=4>
 <tr valign=top align=left>
  <td><?php echo _m("Username:") ?></td>
  <td><input type="text" name="username"
  value="<?php if (isset($this->auth["uname"]))
                     htmlspecialchars(print $this->auth["uname"]); ?>"
  size=16 maxlength=32><br><small><?php echo _m("Type your username or mail") ?></small></td>
 </tr>
 <tr valign=top align=left>
  <td><?php echo _m("Password:") ?></td>
  <td><input type="password" name="password" size=16 maxlength=32></td>
 </tr>
 <tr>
  <td colspan=2 align=center><input type="submit" name="submit" value="<?php echo _m("Login now") ?>"></td>
 </tr>
</table>

 <?php if ( isset($username) ): ?>
 <!-- failed login code -->

 <p>
 <table>
  <tr align=center>
   <td><font color=red><b><?php echo _m("Either your username or your password is not valid.") ?></b></font></td>
  </tr>
  <tr align=center>
   <td><?php echo _m("Please try again!") ?></td>
  </tr>
  <tr align=center>
   <td><?php echo _m("If you are sure you have typed the correct password, please e-mail <a href=mailto:%1>%1</a>.", array(ERROR_REPORTING_EMAIL)) ?></td>
  </tr>
 </table>

 <?php endif ?>

</table>
</center>
</form>
</body>
<script language="JavaScript">
<!--
  if (document.forms[0][0].value != '') {
      document.forms[0][2].focus();
  } else {
      document.forms[0][0].focus();
  }
// -->
</script>
</html>
<!-- $Id$ -->
