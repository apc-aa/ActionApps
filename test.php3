<?php
/** This script has been stolen from old version of Horde.
  * We keep it here to remind us that we shoudl indeed have
  * something like this for installation and troubleshooting
  * purposes. The installation docs refers to it. Maybe the first
  * thing AA user will see after the installation :-|
  *
  * @version $Id$
  * @author Marek Tichy, Econnect
  * @copyright (c) 2002-3 Association for Progressive Communications
*/

/** APC-AA configuration file */
require_once "include/config.php3";
/** Main include file for using session management function on a page */
require_once $GLOBALS['AA_INC_PATH']."locsess.php3";
/** Set of useful functions used on most pages */
require_once $GLOBALS['AA_INC_PATH']."util.php3";
require_once $GLOBALS['AA_INC_PATH']."formutil.php3";

/** Components (plugins) manipulation class */
class Components {
    function getClassNames($type) {
        $right_classes = array();
        $type_lenght   = strlen($type);
        foreach (get_declared_classes() as $classname) {
            if ( substr($classname,0,$type_lenght) == $type ) {
                $right_classes[] = $classname;
            }
        }
        return $right_classes;
    }

    function factory($classname) {
        return new $classname;
    }
}

/** @todo this class should be abstract after we switch to PHP5 */
class Optimize {
    var $messages = array();
    function name()         {}
    function description()  {}
    function test()         {}
    function repair()      {}
    function report()       {
        return join('<br>', $this->messages);
    }
    function clear_report() {
        unset($this->messages);
        $this->messages = array();
    }
}


/** Testing if relation table contain records, where values in both columns are
 *  identical (which was bug fixed in Jan 2006)
 */
class Optimize_db_relation_dups extends Optimize {

    function name() {
        return _m("Relation table duplicate records");
    }

    function description() {
        return _m("Testing if relation table contain records, where values in both columns are identical (which was bug fixed in Jan 2006)");
    }

    function test() {
        $SQL = 'SELECT count(*) as err_count FROM `relation` WHERE `source_id`=`destination_id`';
        $err_count = GetTable2Array($SQL, "aa_first", 'err_count');
        if ($err_count > 0) {
            $this->messages[] = _m('%1 duplicates found', array($err_count));
            return false;
        }
        $this->messages[] = _m('No duplicates found');
        return true;
    }

    function repair() {
        $db  = getDb();
        $SQL = 'DELETE FROM `relation` WHERE `source_id`=`destination_id`';
        $db->query($SQL);
        freeDb($db);
        return true;
    }
}

/** There was change in Reader management functionality in AA v2.8.1 */
class Optimize_readers_login2id extends Optimize{

    function name() {
        return _m("Convert Readers login to reader id");
    }

    function description() {
        return _m("There was change in Reader management functionality in AA v2.8.1, so readers are not internaly identified by its login, but by reader ID (item ID of reader in Reader slice). This is much more powerfull - you can create relations just as in normal slice. It works well without any change. The only problem is, if you set any slice to be editable by users from Reader slice. In that case the fields edited_by........ and posted_by........ are filled by readers login instead of reader id. You can fix it by \"Repair\".");
    }

    function test() {
        $this->clear_report();
        $ret = true;  // which means OK

        // get all readers in array: id => arrary( name => ...)
        $readers = FindReaderUsers('');
        $posted_by_found = $this->_test_field($readers, 'posted_by');
        if (count($posted_by_found) > 0) {
            $this->messages[] = _m('%1 login names from reader slice found as records in item.posted_by which is wrong (There should be reader ID from AA v2.8.1). "Repair" will correct it.', array(count($posted_by_found)));
            $ret = false;
        }
        $edited_by_found = $this->_test_field($readers, 'edited_by');
        if (count($edited_by_found) > 0) {
            $this->messages[] = _m('%1 login names from reader slice found as records in item.edited_by which is wrong (There should be reader ID from AA v2.8.1). "Repair" will correct it.', array(count($edited_by_found)));
            $ret = false;
        }
        return $ret;
    }

    /** test if we can find an item which was edited by reader and is idetified
     *  by login name (instead of item_id)
     *  @returns array of such users
     */
    function _test_field(&$readers, $item_field) {
        // get posted_by, edit_by, ... array:  posted_by => 1
        $SQL     = "SELECT DISTINCT $item_field FROM item";
        $editors = GetTable2Array($SQL, $item_field, 'aa_mark');
        $ret     = array();
        foreach ( $readers as $r_id => $reader ) {
            if ($reader['name'] AND isset($editors[$reader['name']])) {
                $ret[$r_id] = $reader['name'];
            }
        }
        return $ret;
    }

    function repair() {
        $this->clear_report();

        // get all readers in array: id => arrary( name => ...)
        $readers = FindReaderUsers('');
        $posted_by_found = $this->_test_field($readers, 'posted_by');
        $db = getDb();
        if (count($posted_by_found) > 0) {
            foreach ($posted_by_found as $r_id => $r_login ) {
                $SQL = "UPDATE item SET posted_by = '$r_id' WHERE posted_by = '$r_login'";
                $db->query($SQL);
                $this->messages[] = _m('Column item.posted_by updated for %1 (id: %2).', array($r_login, $r_id));
            }
        }
        if (count($edited_by_found) > 0) {
            foreach ($edited_by_found as $r_id => $r_login ) {
                $SQL = "UPDATE item SET edited_by = '$r_id' WHERE edited_by = '$r_login'";
                $db->query($SQL);
                $this->messages[] = _m('Column item.edited_by updated for %1 (id: %2).', array($r_login, $r_id));
            }
        }
        return true;
    }
}

/** Testing if relation table contain records, where values in both columns are
 *  identical (which was bug fixed in Jan 2006)
 */
class Optimize_clear_pagecache extends Optimize {

    function name() {
        return _m("Clear Pagecache");
    }

    function description() {
        return _m("Whole pagecache will be invalidated and deleted");
    }

    function test() {
        $this->messages[] = _m('There is nothing to test.');
        return true;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     */
    function repair() {
        $db  = getDb();
        $db->query('CREATE TABLE pagecache_new LIKE pagecache');
        $this->messages[] = _m('Table pagecache_new created');
        $db->query('CREATE TABLE pagecache_str2find_new LIKE pagecache_str2find');
        $this->messages[] = _m('Table pagecache_str2find_new created');
        $db->query('RENAME TABLE pagecache_str2find TO pagecache_str2find_bak, pagecache TO pagecache_bak');
        $this->messages[] = _m('Renamed tables pagecache_* to pagecache_*_bak');
        $db->query('RENAME TABLE pagecache_str2find_new TO pagecache_str2find, pagecache_new TO pagecache');
        $this->messages[] = _m('Renamed tables pagecache_*_new to pagecache_*');
        $db->query('DROP TABLE pagecache_str2find_bak, pagecache_bak');
        $this->messages[] = _m('Old pagecache_*_bak tables dropped');
        freeDb($db);
        return true;
    }
}



if ($_GET['test'] AND (strpos($_GET['test'], 'Optimize_')===0)) {
    $optimizer = Components::factory($_GET['test']);
    $optimizer->test();
    $body .= $optimizer->report();
}

if ($_GET['repair'] AND (strpos($_GET['repair'], 'Optimize_')===0)) {
    $optimizer = Components::factory($_GET['repair']);
    $optimizer->repair();
    $body .= $optimizer->report();
}

foreach (Components::getClassNames('Optimize_') as $optimize_class) {
    // call static class methods
    $name        = call_user_func(array($optimize_class, 'name'));
    $description = call_user_func(array($optimize_class, 'description'));
    $body .= "
    <div>
      <div style=\"float: right;\">
        <a href=\"?test=$optimize_class\">Test</a>
        <a href=\"?repair=$optimize_class\">Repair</a>
      </div>
      <div>$name<br><small>$description</small></div>
    </div>";
}

$page_setting = array(
    'title' => 'apcaa: System Capabilities Test',
    'body'  => $body );

FrmHtmlPage($page_setting);

exit;



@session_start();
/* Register a session. */
if (!isset($HTTP_SESSION_VARS['apcaa_test_count'])) {
    $apcaa_test_count = 0;
    session_register('apcaa_test_count');
}

$apcaa_test_count = &$HTTP_SESSION_VARS['apcaa_test_count'];

/* We want to be as verbose as possible here. */
error_reporting(E_ALL);
function testErrorHandler($errno, $errmsg, $filename, $linenum, $vars) {
    global $pear, $pearmail, $pearlog, $peardb;
    if (preg_match("/PEAR\.php/", $errmsg)) {
        $pear = false;
    } elseif (preg_match("/RFC822\.php/", $errmsg)) {
        $pearmail = false;
    } elseif (preg_match("/Log\.php/", $errmsg)) {
        $pearlog = false;
    } elseif (preg_match("/DB\.php/", $errmsg)) {
        $peardb = false;
    }
}

function status($foo) {
    if ($foo) {
        echo '<font color="green"><b>Yes</b></font>';
    } else {
        echo '<font color="red"><b>No</b></font>';
    }
}

/* apcaa versions */
//todo - this should be located somewhere clever
$versions['apcaa'] = APC_AA_VERSION;



/* Parse PHP version */
function split_php_version($version)
{
    // First pick off major version, and lower-case the rest.
    if (strlen($version) >= 3 && $version[1] == '.') {
        $phpver['major'] = substr($version, 0, 3);
        $version = substr(strtolower($version), 3);
    } else {
        $phpver['major'] = $version;
        $phpver['class'] = 'unknown';
        return $phpver;
    }
    if ($version[0] == '.') {
        $version = substr($version, 1);
    }
    // Next, determine if this is 4.0b or 4.0rc; if so, there is no minor,
    // the rest is the subminor, and class is set to beta.
    $s = strspn($version, '0123456789');
    if ($s == 0) {
        $phpver['subminor'] = $version;
        $phpver['class'] = 'beta';
        return $phpver;
    }
    // Otherwise, this is non-beta;  the numeric part is the minor,
    // the rest is either a classification (dev, cvs) or a subminor
    // version (rc<x>, pl<x>).
    $phpver['minor'] = substr($version, 0, $s);
    if ((strlen($version) > $s) && ($version[$s] == '.' || $version[$s] == '-')) {
        $s++;
    }
    $phpver['subminor'] = substr($version, $s);
    if ($phpver['subminor'] == 'cvs' || $phpver['subminor'] == 'dev' || substr($phpver['subminor'], 0, 2) == 'rc') {
        unset($phpver['subminor']);
        $phpver['class'] = 'dev';
    } else {
        if (!$phpver['subminor']) {
            unset($phpver['subminor']);
        }
        $phpver['class'] = 'release';
    }
    return $phpver;
}

/* Display PHP version bullets */
function show_php_version($phpver)
{
    echo '    <li>PHP Major Version: ' . $phpver['major'] . "</li>\n";
    if (isset($phpver['minor'])) {
        echo '    <li>PHP Minor Version: ' . $phpver['minor'] . "</li>\n";
    }
    if (isset($phpver['subminor'])) {
        echo '    <li>PHP Subminor Version: ' . $phpver['subminor'] . "</li>\n";
    }
    echo '    <li>PHP Version Classification: ' . $phpver['class'] . "</li>\n";
}

/* PHP version-parsing regression test; PHP version format is only roughly */
/* consistent, thus the need to test a wide range. */
if (false) {
    $phpversions = array('4.0B1', '4.0B2-1', '4.0B2', '4.0B3-RC2', '4.0b3-RC3', '4.0b3-RC4', '4.0b3-RC5', '4.0b3', '4.0b4-rc1', '4.0b4', '4.0b4pl1', '4.0RC1', '4.0RC2', '4.0.0', '4.0.1', '4.0.2-dev', '4.0.2', '4.0.3RC1', '4.0.3RC2', '4.0.3', '4.0.3pl1', '4.0.4RC3', '4.0.4RC5', '4.0.4RC6', '4.0.4', '4.0.4pl1-RC1', '4.0.4pl1', '4.0.5RC1', '4.0.5-dev');
    foreach ($phpversions as $version) {
        echo "    <li>PHP Version: $version</li>\n";
        $phpver = split_php_version($version);
        show_php_version($phpver);
        echo '<br/>';
    }
}

/* PHP Version */
$phpver = split_php_version(phpversion());

/* PHP module capabilities */
$ftp = extension_loaded('ftp');
$gettext = extension_loaded('gettext');
$imap = extension_loaded('imap');
$ldap = extension_loaded('ldap');
$mcal = extension_loaded('mcal');
$mcrypt = extension_loaded('mcrypt');
$mysql = extension_loaded('mysql');
$pgsql = extension_loaded('pgsql');
$xml = extension_loaded('xml');

/* PHP Settings */
$magic_quotes_runtime = !get_magic_quotes_runtime();

/* PEAR */
$pear = true;
$pearmail = true;
$pearlog = true;
$peardb = true;
set_error_handler('testErrorHandler');
include 'PEAR.php';
include 'Mail/RFC822.php';
include 'Log.php';
include 'DB.php';
restore_error_handler();

/* Check the version of the pear database API. */
if ($peardb) {
    $peardbversion = '0';
    $peardbversion = @DB::apiVersion();
    if ($peardbversion < 2) {
        $peardb = false;
    }
}

echo '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "DTD/xhtml1-transitional.dtd">';

/* Handle special modes */
if (isset($HTTP_GET_VARS['mode'])) {
    switch ($HTTP_GET_VARS['mode']) {
    case 'phpinfo':
        phpinfo();
        exit;
        break;

    case 'unregister':
        $HTTP_SESSION_VARS['apcaa_test_count'] = null;
        session_unregister('apcaa_test_count');
        ?>
        <html>
        <body bgcolor="white" text="black">
        <font face="Helvetica, Arial, sans-serif" size="2">
        The test session has been unregistered.<br>
        <a href="test.php">Go back</a> to the test.php page.<br>
        <?php
        exit;
        break;

    default:
        break;
    }
} else {
?>

<html>
<head>
<title>apcaa: System Capabilities Test</title>
<style type="text/css">
<!--
body { font-family: Geneva,Arial,Helvetica,sans-serif; font-size: 10pt; }
td { font-family: Geneva,Arial,Helvetica,sans-serif; font-size: 10pt; }
h1 { font-size: 12pt; color: black; font-family: Verdana,Geneva,Arial,Helvetica,sans-serif; }
-->
</style>
</head>

<body bgcolor="#ffffff" text="#000000">

<table border="0" cellpadding="2" cellspacing="0" width="100%">
<tr><td>

<h1>APC-AA Version</h1>
<ul>
<?php
echo "<li>".APC_AA_VERSION;
?>
</ul>

<h1>PHP Version</h1>
<ul>
    <li><a href="test.php?mode=phpinfo">View phpinfo() screen</a></li>
    <li>PHP Version: <?php echo phpversion(); ?></li>
<?php
    show_php_version($phpver);
    if ($phpver['major'] < '4.0') {
        echo '        <li><font color="red">You need to upgrade to PHP4. PHP3 will not work.</font></li>';
        $requires = 1;
    } elseif ($phpver['major'] == '4.0') {
        if ($phpver['class'] == 'beta' || $phpver['class'] == 'unknown') {
            echo '        <li><font color="red">This is a beta/prerelease version of PHP4. You need to upgrade to a release version.</font></li>';
            $requires = 1;
        } elseif ($phpver['minor'] < '5' || ($phpver['minor'] == 4 && (empty($phpver['subminor']) || $phpver['subminor'][0] != 'p'))) {
            echo '        <li><font color="red">This version of PHP is not supported. You need to upgrade to a more recent version.</font></li>';
            $requires = 1;
        } elseif ($phpver['minor'] < '7') {
            echo '        <li><font color="orange">This version of PHP is supported but the PEAR library that comes with this version is not recent enough. See <a href="http://apcaa.org/pear/">http://apcaa.org/pear/</a> for details.</font></li>';
            $requires = 1;
        } else {
            echo '        <li><font color="green">You are running a supported version of PHP.</font></li>';
        }
    } elseif ($phpver['major'] == '4.1' || $phpver['major'] == '4.2'|| $phpver['major'] == '4.3'|| $phpver['major'] == '4.4') {
        echo '        <li><font color="green">You are running a supported version of PHP.</font></li>';
    } else {
        echo '        <li><font color="orange">Wow, a mystical version of PHP from the future. Let <a href="mailto:dev@lists.apcaa.org">dev@lists.apcaa.org</a> know what version you have so we can fix this script.</font></li>';
    }
    if (!empty($requires)) {
        echo '        <li>apcaa requires at least PHP 4.0.5 and PEAR 4.0.7.</li>';
    }
    echo '</ul>';
?>

<h1>PHP Module Capabilities</h1>
<ul>
    <li>FTP Support: <?php status($ftp); ?></li>
    <li>Gettext Support: <?php status($gettext); ?></li>
    <li>IMAP Support: <?php status($imap) ?></li>
    <li>LDAP Support: <?php status($ldap); ?></li>
    <li>MCAL Support: <?php status($mcal); ?></li>
    <li>Mcrypt Support: <?php status($mcrypt); ?></li>
    <li>MySQL Support: <?php status($mysql); ?></li>
    <li>PostgreSQL Support: <?php status($pgsql); ?></li>
    <li>XML Support: <?php status($xml); ?></li>
</ul>

<h1>Miscellaneous PHP Settings</h1>
<ul>
    <li>magic_quotes_runtime set to Off: <?php echo status($magic_quotes_runtime); ?></li>
    <?php if (!$magic_quotes_runtime) { ?>
    <li><font color="red"><b>magic_quotes_runtime may cause problems with database inserts, etc. Turn it off.</b></font></li>
    <?php } ?>
</ul>

<h1>PHP Sessions</h1>
<?php $apcaa_test_count++; ?>
<ul>
    <li>Session counter: <?php echo $apcaa_test_count; ?></li>
    <li>To unregister the session: <a href="test.php?mode=unregister">click here</a></li>
</ul>

<h1>PEAR</h1>
<ul>
    <li>PEAR - <?php status($pear); ?></li>
    <?php if (!$pear) { ?>
        <li><font color="red">Check your PHP include_path setting to make sure it has the PEAR library directory.</font></li>
    <?php } ?>
    <li>Mail::RFC822 - <?php status($pearmail); ?></li>
    <?php if ($pear && !$pearmail) { ?>
        <li><font color="red">Make sure you're using a recent version of PEAR which includes the Mail class.</font></li>
    <?php } ?>
    <li>Log - <?php status($pearlog); ?></li>
    <?php if ($pear && !$pearlog) { ?>
        <li><font color="red">Make sure you have installed the latest PEAR from PHP-cvs.</font></li>
    <?php } ?>
    <li>DB - <?php status($peardb); ?></li>
    <?php if ($pear && !$peardb) {
              if ($peardbversion) { ?>
                  <li><font color="red">Your version of DB is not recent enough.</font></li>
              <?php } else { ?>
                  <li><font color="red">You will need DB if you're using SQL drivers for preferences, contacts (Turba), etc.</font></li>
              <?php }
          } ?>
</ul>

<p align="left">
<a href="http://validator.w3.org/check/referer"><img src="http://validator.w3.org/images/vxhtml10" alt="Valid XHTML 1.0!" height="31" width="88" border="0" hspace="5" /></a>
</p>

</td></tr>
</table>

<?php } ?>

</body>
</html>
