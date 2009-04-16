<?php

if ( isset($_REQUEST['GLOBALS']) OR isset($_REQUEST['AA_BASE_PATH']) OR isset($_REQUEST['AA_INC_PATH'])) {
    echo "Atempt to redefine GLOBALS - Exiting. See config.";
    exit;
}

// we should move it to all config files
define('AA_PHPTHUMB_PATH', "/data/www/aa/misc/phpThumb/");   // do not change

switch ($SERVER_ADDR) {
    case "89.250.245.116":
        define (VIRT_CONFIG, "config.cmvu.cz.php"); break;
    case "89.250.245.117":
        define (VIRT_CONFIG, "config.theconstellation.ca.php"); break;
    case "89.250.245.118":
        define (VIRT_CONFIG, "config.biom.cz.php"); break;
    case "89.250.245.119":
        define (VIRT_CONFIG, "config.spotrebitele.info.php"); break;
    case "89.250.245.120":
        define (VIRT_CONFIG, "config.commons.ca.php"); break;
    case "89.250.245.121":
        define (VIRT_CONFIG, "config.cape.ca.php"); break;
    case "89.250.245.123":
        define (VIRT_CONFIG, "config.cela.ca.php"); break;
    case "89.250.245.125":
        define (VIRT_CONFIG, "config.healthyenvironmentforkids.ca.php"); break;
    default:
        if ( strpos( $_SERVER["SERVER_NAME"], 'demo.actionapps') !== false ) {
            define (VIRT_CONFIG, "config.demo.actionapps.org.php");
        } elseif ( strpos( $_SERVER["SERVER_NAME"], 'actionapps') !== false ) {
            define (VIRT_CONFIG, "config.actionapps.org.php");
        } elseif ( strpos( $_SERVER["SERVER_NAME"], 'detem.mzp.cz') !== false ) {
            define (VIRT_CONFIG, "config.detem.mzp.cz.php");
        } elseif ( strpos( $_SERVER["SERVER_NAME"], 'srchc.ecn.cz') !== false ) {
            define (VIRT_CONFIG, "config.healthyenvironmentforkids.ca.php");
        } elseif ( strpos( $_SERVER["SERVER_NAME"], 'srchc.com') !== false ) {
            define (VIRT_CONFIG, "config.healthyenvironmentforkids.ca.php");
        } elseif ( (strpos( $_SERVER["SERVER_NAME"], 'daphne.cz') !== false)  OR (strpos( 'x'.$_SERVER["SERVER_NAME"], 'zdravakrajina.cz') !== false) ) {
            define (VIRT_CONFIG, "config.daphne.cz.php");
        } elseif ( (strpos( $_SERVER["SERVER_NAME"], 'smoke-fx') !== false)) {
            define (VIRT_CONFIG, "config.smoke-fx.com.php");
        } elseif ( (strpos( $_SERVER["SERVER_NAME"], 'evs.cz') !== false)  OR (strpos( 'x'.$_SERVER["SERVER_NAME"], 'evs.ecn.cz') !== false) ) {
            define (VIRT_CONFIG, "config.evs.cz.php");
        } elseif (strpos( $_SERVER["SERVER_NAME"], 'changenet.ecn.cz') !== false ) {
            define (VIRT_CONFIG, "config.changenet.ecn.cz.php");
        } elseif ( strpos( $_SERVER["SERVER_NAME"], 'mpo-efekt.cz') !== false ) {
            define (VIRT_CONFIG, "config.mpo-efekt.cz.php");
        } elseif ( strpos( $_SERVER["SERVER_NAME"], 'ekowatt.cz') !== false ) {
            define (VIRT_CONFIG, "config.ekowatt.cz.php");
        } elseif ( strpos( $_SERVER["SERVER_NAME"], 'biomassscience') !== false ) {
            define (VIRT_CONFIG, "config.biomassscience.org.php");
        } elseif ( strpos( $_SERVER["SERVER_NAME"], 'marek') !== false ) {
            define (VIRT_CONFIG, "config.marek.ecn.cz.php");
        } else {
            define (VIRT_CONFIG, "config.default.php");
        }
}

require_once ($path.VIRT_CONFIG);
?>
