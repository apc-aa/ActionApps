<SCRIPT language="php">
//$Id$

// MOVED TO ../include/doit_perm_test.inc
// it is safer there

// ---------------------------------------------------------------
// definitions normally done in other AA components.

define("L_USER",'user');  define("L_GROUP",'group');
define("MAX_GROUPS_DEEP", 2);

// this is only necessary for testing perm_sql.php3
require_once("perm_sql.php3");


// ---------------------------------------------------------------
//internal testing functions
// ---------------------------------------------------------------
// START TESTING


//$db->query("delete from membership");
//$db->query("delete from perms");

// -----------------------------------------------------
// END

// currently not used, but might be useful
function uniq_array($haystack)
{
  $uniq;
  for($i=0;$i<count($haystack);$i++){
    if (! in_array($haystack[$i],$uniq))
      $uniq [] = $haystack[$i];
  };
  $uniq = sort( $uniq );
  return $uniq;
}


/*
$Log$
Revision 1.2  2003/02/05 15:10:42  jakubadamek
changing require to require_once

Revision 1.1.1.1  2000/06/21 18:40:13  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:01  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.2  2000/03/22 09:37:18  madebeer
cleaned up doit.php3

*/
</SCRIPT>

