<?php

require_once "../../include/config.php3";    // scripts do so (/misc/x.php3)
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."varset.php3";

/** This is the file used in Colnodo to fill central_conf table for AA Central.
 *  We expect the AA config files for all the AAs in /data/www/aa-config 
 *  directory.
 *  conffiler.php takes each file and ask the conf reader for the extraction 
 *  of the data from the conf file. We have to use this technique, since it is 
 *  impossible to redefine the constants in PHP, so we have to include 
 *  the config.php files to clean HTTP request.
 */  
    
crash  - comment this but then uncoment it again, since it is very dangerous 

$db = getDb();

if ($handle = opendir('/data/www/aa-config/')) {
    echo "Directory handle: $handle\n";
    echo "Files:\n";

    $varset = new CVarset;
    $i=1;
    /* This is the correct way to loop over the directory. */
    while (false !== ($file = readdir($handle))) {
        // do not want files like '.', '..'
        if (substr($file,-5) != '.conf') {
            continue;
        }
        $result = file_get_contents("http://odm.colnodo.apc.org/apc-aa-dev/misc/test/confreader.php?file=". urlencode($file));
        print_r($file);
        print_r($result);
        $conf   = unserialize($result);
        $varset->clear();
        foreach ( $conf as $k=>$v ) {
            $varset->add($k, 'text', $v);
        }
        $varset->doInsert('central_conf');
    }
    closedir($handle);
}


freeDb($db);

/*
require($_REQUEST['file']);
$conf = array( 'dns_conf'                  => '',
               'dns_serial'                => '',
               'dns_web'                   => '',
               'dns_mx'                    => '',
               'dns_db'                    => '',
               'dns_prim'                  => '',
               'dns_sec'                   => '',
               'web_conf'                  => $_REQUEST['file'],
               'web_path'                  => THIS_AA_FILES_PATH,
               'db_server'                 => DB_HOST,
               'db_name'                   => DB_NAME,
               'db_user'                   => DB_USER,
               'db_pwd'                    => DB_PASSWORD,
               'AA_SITE_PATH'              => AA_SITE_PATH,
               'AA_BASE_DIR'               => AA_BASE_DIR,
               'AA_HTTP_DOMAIN'            => AA_HTTP_DOMAIN,
               'AA_ID'                     => AA_ID,
               'ORG_NAME'                  => ORG_NAME,
               'ERROR_REPORTING_EMAIL'     => ERROR_REPORTING_EMAIL,
               'ALERTS_EMAIL'              => ALERTS_EMAIL,
               'IMG_UPLOAD_MAX_SIZE'       => IMG_UPLOAD_MAX_SIZE,
               'IMG_UPLOAD_URL'            => IMG_UPLOAD_URL,
               'IMG_UPLOAD_PATH'           => IMG_UPLOAD_PATH,
               'SCROLLER_LENGTH'           => SCROLLER_LENGTH,
               'FILEMAN_BASE_DIR'          => FILEMAN_BASE_DIR,
               'FILEMAN_BASE_URL'          => FILEMAN_BASE_URL,
               'FILEMAN_UPLOAD_TIME_LIMIT' => FILEMAN_UPLOAD_TIME_LIMIT,
               'AA_ADMIN_USER'             => AA_ADMIN_USER,
               'AA_ADMIN_PWD'              => AA_ADMIN_PWD,
               'status_code'               => 1
             );
*/
?>
