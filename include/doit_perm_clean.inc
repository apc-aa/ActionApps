<SCRIPT language="php">
//$Id$

// normally this is set in extsess.php3
$db = new DB_AA;

$db->query("delete from users");
$db->query("delete from membership");
$db->query("delete from perms");

/*
$Log$
Revision 1.1  2000/06/21 18:40:27  madebeer
Initial revision

Revision 1.1.1.1  2000/06/12 21:50:14  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.1  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
</SCRIPT>
