<SCRIPT language="php">
//$Id$
// demo

  $array[uid] = 'demo';
  $array[mail] = 'demo@igc.org';
  $array["givenname"] = "NewsFlash";
  $array["sn"] = "Demo";
  $array["userpassword"] = 'demo';

  $user_id = AddUser($array);

// staff-only admin

  $array[uid] = 'igc-admin';
  $array[mail] = 'igc-admin@igc.org';
  $array["givenname"] = "Admin";
  $array["sn"] = "IGC Staff";
  $array["userpassword"] = 'demo!';

  $user_id = AddUser($array);

// staff-only author

  $array[uid] = 'igc-staff';
  $array[mail] = 'igc-staff@igc.org';
  $array["givenname"] = "Authors";
  $array["sn"] = "IGC Staff";
  $array["userpassword"] = 'demo!';

  $user_id = AddUser($array);

// corpwatch

  $corpwatch = array( mail => 'corpwatch@igc.org', 
                      uid => 'corpwatcho', 
                      givenname => "Tristi", 
                      sn        => "Tanaka",
                      userpassword  => "all2good");

  $user_id = AddUser($corpwatch);

// iearn

  $iearn     = array( mail => 'iearn@igc.org', 
                      uid  => 'iearno', 
                      givenname => "Ed", 
                      sn        => "Gragert",
                      userpassword  => "even!bet");

  $user_id = AddUser($iearn);

   $corpwatch = array( mail => 'corpwatch@corpwatch.org',
                       uid => 'corpwatch', 
                       givenname => "Tristi",
                       sn        => "Tanaka",
                       userpassword  => "all2good");

   echo  AddUser($corpwatch);
   echo "\n";

   $iearn = array( mail => 'ed1@us.iearn.org',
                       uid => 'iearn', 
                       givenname => "Ed",
                       sn        => "Gragert",
                       userpassword  => "way2good");

   echo  AddUser($iearn);

</SCRIPT>
