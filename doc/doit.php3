<SCRIPT language="php">

/* $Id$
$Log$
Revision 1.2  2000/07/11 12:05:18  kzajicek
Renamed config.inc to config.php3

Revision 1.1.1.1  2000/06/21 18:40:16  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:02  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.4  2000/04/04 18:09:46  madebeer
removed author section for big_srch, included needed librariees in doit.php3
made perm_sql.php3 AuthenticateUsername more robust.

Revision 1.3  2000/03/22 09:37:18  madebeer
cleaned up doit.php3

Revision 1.2  2000/03/16 22:16:56  madebeer
aadb.sql now includes users.uid

*/
// ---------------------------------------------------------------
// definitions normally done in other AA components.

define("L_USER",'user');  define("L_GROUP",'group');
define("MAX_GROUPS_DEEP", 2);
echo "begin";
require("../include/config.php3");
require("../include/locsessi.php3");
require("../include/perm_".PERM_LIB.".php3");

/*
class DB_AA extends IGC_DB {  var $Database = DB_NAME;   };

// ---------------------------------------------------------------
// START

// you should comment these out after you have run it once.

// CAREFUL!!!
$db = new DB_AA;
$db->query("delete from users");

*/
/*
require("../include/doit_test_utils.php3");       // handy testing routines
require("../include/doit_perm_test.inc");        // run perms test

// -------------- test summary 
echo "TEST result:  
      $num_fail FAILED
      $num_pass PASSED
";
*/

#require("../include/doit_perm_clean.inc");       // deletes users from mysql
require("../include/doit_perm_admin_user.inc");  // adds a superuser
#require("../include/doit_perm_first_users.inc"); // adds other users
echo "end";
</SCRIPT>
