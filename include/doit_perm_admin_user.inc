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

</SCRIPT>
