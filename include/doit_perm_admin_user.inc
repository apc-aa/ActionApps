<SCRIPT language="php">
//$Id$

// prepare sample user data, we'll use it for other functions too

  $username = ADMIN_USER;
  $password = ADMIN_PASS;

  $array[uid] = $username;
  $array[mail] = $username."@".DEFAULT_ORG_ID;
  $array["givenname"] = "AA";
  $array["sn"] = "Admin";
  $array["userpassword"] = $password;

// admin

  $user_id = AddUser($array);
  echo "created $username ($user_id)\n";
  AddPerm($user_id, AA_ID, 'aa', 4);

/*
$Log$
Revision 1.2  2000/08/12 15:34:18  kruemelmo
AA is a better name than NewsFlash for the AA admin.

Revision 1.1.1.1  2000/06/21 18:40:27  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:14  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.3  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
</SCRIPT>

