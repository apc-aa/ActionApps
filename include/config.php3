<?php


/*
 * Definicion de los dominios
 */

if ( (isset($_REQUEST['GLOBALS'])) || ($_REQUEST['AA_BASE_PATH']) ||($_REQUEST['AA_INC_PATH'])){
    exit;
}

define('COLNODO_LOCAL_INC_DIR', '/var/www/apc-aa-config/');

#define('DB_ERROR_PAGE','http://www.colnodo.apc.org/errorbase.html');


$COLNODO_DOMAINS = array(

//www.soytic.gov.co
        'soytic.gov.co'  => 'www.soytic.gov.co.conf',
        'www.soytic.gov.co'  => 'www.soytic.gov.co.conf',
//cursos.soytic.gov.co
        'cursos.soytic.gov.co'  => 'cursos.soytic.gov.co.conf',
        'www.cursos.soytic.gov.co'  => 'cursos.soytic.gov.co.conf',
//mintic.colnodo.apc.org
        'mintic.colnodo.apc.org'  => 'mintic.colnodo.apc.org.conf',
        'www.seguimiento.soytic.gov.co'  => 'mintic.colnodo.apc.org.conf',
        'seguimiento.soytic.gov.co'  => 'mintic.colnodo.apc.org.conf',
        'www.estadisticas.soytic.gov.co'  => 'mintic.colnodo.apc.org.conf',
        'estadisticas.soytic.gov.co'  => 'mintic.colnodo.apc.org.conf',
//entidades.colnodo.apc.org
        'entidades.colnodo.apc.org'  => 'entidades.colnodo.apc.org.conf',
        'www.entidades.colnodo.apc.org'  => 'entidades.colnodo.apc.org.conf',
        'entidades.gobiernoenlinea.gov.co'  => 'entidades.colnodo.apc.org.conf',
        'www.entidades.gobiernoenlinea.gov.co'  => 'entidades.colnodo.apc.org.conf',
//biblioteca.colnodo.apc.org
        'biblioteca.colnodo.apc.org'  => 'biblioteca.colnodo.apc.org.conf',
        'www.biblioteca.soytic.gov.co'  => 'biblioteca.colnodo.apc.org.conf',
        'biblioteca.soytic.gov.co'  => 'biblioteca.colnodo.apc.org.conf',



);
$this_colnodo_domain = $_SERVER['SERVER_NAME'];

/*
 *  PARTE GLOBAL GENERAL PARA TODOS DEL ARCHIVO DE CONFIGURACION
 */

/** $Id$
 *
 * Application wide configuration options
 *
 * This is the Action Application main configuration file. In fact, this file
 * is a php script which is included into every php program, thus, php syntax
 * is used. This basically means that this file defines constants in the form:
 *        $name = "value";
 *    or in the form
 *        define("name", "value);
 */

/** AA_SITE_PATH defines the webserver's home directory. It must be an absolute
 *  path from the root. Make sure to terminate this path with a slash!
 *  Fill in the correct value between the quotes.
 *  It normaly looks like:
 *  define('AA_SITE_PATH', "/home/httpd/html/");
 */
 // moved to specific AA config files, bacause it is different for all the AAs
 // and constants are NOT POSSIBLE REDEFINE
 // (with this uncommented you will not be able to use phpThumb - like {img...})
 // define('AA_SITE_PATH', "/var/www/");

 // however - we need the PATH to AA - see  AA_BASE_PATH below
 // Honza 20.11.2012

/** AA_BASE_DIR defines AA directory under AA_SITE_PATH where is AA installed.
 *  If you concaternate AA_SITE_PATH and AA_BASE_DIR, you should get absolute
 *  path from root to AA directory (where file slice.php3 is in).
 *  Make sure to terminate this path with a slash!
 *  Example:
 *  define('AA_BASE_DIR', "apc-aa/");
 */
define('AA_BASE_DIR', "apc-aa/"); // AA_BASE_DIR is only used in this file so that
                                  // a single change is required for multiple AA
                                  // versions

// base directory for AA
define('AA_BASE_PATH', '/var/www/'. AA_BASE_DIR);


/*
 *  FIN PARTE GLOBAL GENERAL PARA TODOS DEL ARCHIVO DE CONFIGURACION
 */


/*
 * Se hace el llamado a los diferentes archivos
 *  de configuracion de las AA en Colnodo.
 */

// used for sql_update.php3 for batch database update to 2.10
// Honza 3.8.2006
// if ($config2include) {
//   include(COLNODO_LOCAL_INC_DIR. $config2include);
// } elseif (array_key_exists($this_colnodo_domain,$COLNODO_DOMAINS)){

if (array_key_exists($this_colnodo_domain,$COLNODO_DOMAINS)){
   include(COLNODO_LOCAL_INC_DIR. $COLNODO_DOMAINS[$this_colnodo_domain]);
} else {
   include(COLNODO_LOCAL_INC_DIR. "mintic.colnodo.org.conf");
}

?>

