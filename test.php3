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
require_once AA_INC_PATH."locsess.php3";
/** Set of useful functions used on most pages */
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."formutil.php3";

require_once AA_INC_PATH."optimize.class.php3";
echo $_SERVER["SERVER_NAME"]. '<br><br>';
$fix = new AA_Optimize_Db_Binary_Traing_Zeros();
$fix->repair();
echo $fix->report();
exit;

@session_start();
/* Register a session. */
if (!isset($_SESSION['apcaa_test_count'])) {
    $apcaa_test_count = 0;
    session_register('apcaa_test_count');
}

$apcaa_test_count = &$_SESSION['apcaa_test_count'];

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
$versions['apcaa'] = aa_version();



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
require_once 'PEAR.php';
require_once 'Mail/RFC822.php';
require_once 'Log.php';
require_once 'DB.php';
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
if (isset($_GET['mode'])) {
    switch ($_GET['mode']) {
    case 'phpinfo':
        phpinfo();
        exit;
        break;

    case 'unregister':
        $_SESSION['apcaa_test_count'] = null;
        session_unregister('apcaa_test_count');
        ?>
        <html>
        <body bgcolor="white" text="black">
        <font face="Helvetica, Arial, sans-serif" size="2">
        The test session has been unregistered.<br>
        <a href="test.php3">Go back</a> to the test.php3 page.<br>
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
    <li><a href="test.php3?mode=phpinfo">View phpinfo() screen</a></li>
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
    <li>To unregister the session: <a href="test.php3?mode=unregister">click here</a></li>
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
