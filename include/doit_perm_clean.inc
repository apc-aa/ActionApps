<SCRIPT language="php">
//$Id$

// normally this is set in extsess.php3
$db = new DB_AA;

$db->query("delete from users");
$db->query("delete from membership");
$db->query("delete from perms");

</SCRIPT>
