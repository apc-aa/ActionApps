<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


require_once __DIR__. "/include/init_central.php";
require_once AA_INC_PATH.     'formutil.php3';
require_once AA_INC_PATH.     'files.class.php3';
require_once AA_INC_PATH.     "msgpage.php3";

pageOpen();

if (!IsSuperadmin()) {
  MsgPageMenu(StateUrl(self_base())."index.php3", _m("You don't have permissions to import sites from CSV."), "admin");
  exit;
}

if (is_array($_FILES['csv_filex'])) {

    if ($_FILES["csv_filex"]["error"] == UPLOAD_ERR_OK) {
        $varset = new CVarset;
        $handle = fopen($_FILES["csv_filex"]["tmp_name"], 'r');

        $keys = false;
        while (($data = fgetcsv($handle, 10000)) !== FALSE) {
            if (!$keys) {
                // must be first row with field names
                $keys = $data;
                continue;
            }
            $varset->clear();
            $data_array = array_combine($keys, $data);
            $data_array['status_code'] = 1;
            $varset->resetFromRecord($data_array);
            $varset->doInsert('central_conf');
            $Msg .=  '<br>'. $data_array['ORG_NAME']. ' (' .$data_array['AA_HTTP_DOMAIN'] .') '. _m('inserted');
        }
        fclose($handle);
        $Msg .=  '<br>'. _m('Done');
    }
}


HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<TITLE><?php echo _m("Central - Copy Slice (1/2) - Select Source ActionApps"); ?></TITLE>
</HEAD>
<BODY>
<?php
$useOnLoad = true;
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "central", "copyslice");

echo "<H1><B>" . _m("Central - Import Sites from CSV") . "</B></H1>";
PrintArray($err);
echo $Msg;

$form_buttons = array ("submit");
?>
<form name=f method=post enctype="multipart/form-data" action="<?php echo StateUrl(self_base() ."import_csv.php") ?>">
<?php
FrmTabCaption('', '','', $form_buttons, $sess, $slice_id);
FrmInputFile('csv_file', _m('CSV file'), '', true, "*/*", _m('CSV file for upload. Each AA on its own row, fields seprted by comma, the fields must be in following order (just like in central_conf table):<br>dns_conf, dns_serial, dns_web, dns_mx, dns_db, dns_prim, dns_sec, web_conf, web_path, db_server, db_name, db_user, db_pwd, AA_SITE_PATH, AA_BASE_DIR, AA_HTTP_DOMAIN, AA_ID, ORG_NAME, ERROR_REPORTING_EMAIL, ALERTS_EMAIL, IMG_UPLOAD_MAX_SIZE, IMG_UPLOAD_URL, IMG_UPLOAD_PATH, SCROLLER_LENGTH, FILEMAN_BASE_DIR, FILEMAN_BASE_URL, FILEMAN_UPLOAD_TIME_LIMIT, AA_ADMIN_USER, AA_ADMIN_PWD'));
FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</form>
<?php
HtmlPageEnd();
page_close();

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

