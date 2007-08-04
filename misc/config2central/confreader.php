<?php

/** This is the file used in Colnodo to fill central_conf table for AA Central.
 *  We expect the AA config files for all the AAs in /data/www/aa-config 
 *  directory.
 *  conffiler.php takes each file and ask the conf reader for the extraction 
 *  of the data from the conf file. We have to use this technique, since it is 
 *  impossible to redefine the constants in PHP, so we have to include 
 *  the config.php files to clean HTTP request.
 */  
    
crash  - comment this but then uncoment it again, since it is very dangerous 

require('/data/www/aa-config/'.$_REQUEST['file']);
$conf = array( 'dns_conf'                  => '',
               'dns_serial'                => '',
               'dns_web'                   => '',
               'dns_mx'                    => '',
               'dns_db'                    => '',
               'dns_prim'                  => '',
               'dns_sec'                   => '',
               'web_conf'                  => $_REQUEST['file'],
               'web_path'                  => defined('THIS_AA_FILES_PATH')         ? THIS_AA_FILES_PATH : '',
               'db_server'                 => defined('DB_HOST')                    ? DB_HOST            : '',
               'db_name'                   => defined('DB_NAME')                    ? DB_NAME            : '',
               'db_user'                   => defined('DB_USER')                    ? DB_USER            : '',
               'db_pwd'                    => defined('DB_PASSWORD')                ? DB_PASSWORD        : '',
               'AA_SITE_PATH'              => defined('AA_SITE_PATH')               ? AA_SITE_PATH       : '/var/www/',
               'AA_BASE_DIR'               => defined('AA_BASE_DIR')                ? AA_BASE_DIR        : '',
               'AA_HTTP_DOMAIN'            => defined('AA_HTTP_DOMAIN')             ? AA_HTTP_DOMAIN     : '',
               'AA_ID'                     => defined('AA_ID')                      ? AA_ID              : '',
               'ORG_NAME'                  => defined('ORG_NAME')                   ? ORG_NAME           : '',
               'ERROR_REPORTING_EMAIL'     => defined('ERROR_REPORTING_EMAIL')      ? ERROR_REPORTING_EMAIL : '',
               'ALERTS_EMAIL'              => defined('ALERTS_EMAIL')               ? ALERTS_EMAIL       : '',
               'IMG_UPLOAD_MAX_SIZE'       => defined('IMG_UPLOAD_MAX_SIZE')        ? IMG_UPLOAD_MAX_SIZE : '',
               'IMG_UPLOAD_URL'            => defined('IMG_UPLOAD_URL')             ? IMG_UPLOAD_URL     : '',
               'IMG_UPLOAD_PATH'           => defined('IMG_UPLOAD_PATH')            ? IMG_UPLOAD_PATH    : '',
               'SCROLLER_LENGTH'           => defined('SCROLLER_LENGTH')            ? SCROLLER_LENGTH    : '',
               'FILEMAN_BASE_DIR'          => defined('FILEMAN_BASE_DIR')           ? FILEMAN_BASE_DIR   : '',
               'FILEMAN_BASE_URL'          => defined('FILEMAN_BASE_URL')           ? FILEMAN_BASE_URL   : '',
               'FILEMAN_UPLOAD_TIME_LIMIT' => defined('FILEMAN_UPLOAD_TIME_LIMIT')  ? FILEMAN_UPLOAD_TIME_LIMIT : '',
               'AA_ADMIN_USER'             => defined('AA_ADMIN_USER')              ? AA_ADMIN_USER      : '',
               'AA_ADMIN_PWD'              => defined('AA_ADMIN_PWD')               ? AA_ADMIN_PWD       : '',
               'status_code'               => 1
             );

echo serialize($conf);
?>
