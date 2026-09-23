<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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


require_once __DIR__."/include/init_central.php";
require_once __DIR__ . "/../include/formutil.php";

use AA\Central\CsvImport;
use AA\IO\DB\DB_AA;


pageOpen();

if (!IsSuperadmin()) {
  AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You don't have permissions to import sites from CSV."), "admin");
  exit;
}

$err     = [];
$Msg     = '';
$columns = AA::Metabase()->getColumnNames('central_conf');
$upload  = $_FILES['csv_filex'] ?? null;   // FrmInputFile('csv_file') names its file input csv_file + 'x'
if (is_array($upload)) {
    if (($upload['error'] !== UPLOAD_ERR_OK) || !is_uploaded_file($upload['tmp_name']) || !($handle = fopen($upload['tmp_name'], 'rb'))) {
        $err[] = _m('No CSV file received (upload error %1).', [(int) $upload['error']]);
    } else {
        $records = CsvImport::readRecords($handle);
        fclose($handle);
        $import  = CsvImport::parse(array_shift($records) ?? [], $records, $columns);
    }
}
if (isset($import)) {
    // never print CSV values other than ORG_NAME and AA_HTTP_DOMAIN - the file holds passwords
    switch ($import['error']) {
        case CsvImport::ERR_BLANK_HEADER:
            $err[] = _m('The first line is empty. It must hold the column names.');
            break;
        case CsvImport::ERR_SEMICOLON:
            $err[] = _m('The file looks semicolon-separated. Separate the fields by comma.');
            break;
        case CsvImport::ERR_NO_HEADER:
            $err[] = _m('The first line must hold the column names (see below), none of them was found.');
            break;
        case CsvImport::ERR_DUPLICATE:
            $err[] = _m('Column %1 is in the first line twice, nothing was imported.', [safe($import['error_arg'])]);
            break;
        default:
            try {
                $failed_row = 0;
                DB_AA::transaction(static function () use ($import, &$failed_row) {
                    foreach ($import['rows'] as $row_no => $row) {
                        // nohalt: a DB error must not die() printing the INSERT, passwords included
                        if (!AA::Metabase()->doInsert('central_conf', $row, 'nohalt')) {
                            $failed_row = $row_no;
                            throw new RuntimeException('central_conf insert failed');
                        }
                    }
                });
                foreach ($import['rows'] as $row) {
                    $Msg .= '<br>' . safe($row['ORG_NAME'] ?? '') . ' (' . safe($row['AA_HTTP_DOMAIN'] ?? '') . ') ' . _m('inserted');
                }
                if ($import['ignored']) {
                    $Msg .= '<br>' . _m('Ignored columns') . ': ' . safe(implode(', ', $import['ignored']));
                }
                foreach ($import['rejected'] as $rejected) {
                    $err[] = _m('Row %1 skipped - it has %2 fields, the first row has a different number.', [$rejected['row'], $rejected['fields']]);
                }
                $Msg .= '<br>' . _m('Done');
            } catch (RuntimeException) {
                // an insert or DB_AA's commit failed; the exception text is not shown
                $err[] = $failed_row
                    ? _m('Database error at row %1 - import rolled back, nothing was inserted.', [$failed_row])
                    : _m('Database error - import rolled back, nothing was inserted.');
            }
    }
}


$apage = new AA_Adminpageutil('central', 'importcsv');
$apage->setTitle(_m("Central - Import Sites from CSV"));
$apage->setForm();
$apage->printHead($err, $Msg);

$form_buttons = ["submit"];
?>
<form name=f method=post enctype="multipart/form-data" action="<?php echo StateUrl(self_base() ."import_csv.php") ?>">
<?php
FrmTabCaption('', $form_buttons);
FrmInputFile('csv_file', _m('CSV file'), '', true, "*/*", _m("CSV file for upload, fields separated by comma. The first row holds the column names, in any order; each following row is one AA. Accepted column names (others are ignored):<br>%1", [implode(', ', array_diff($columns, CsvImport::NOT_IMPORTED))]));
FrmTabEnd($form_buttons);
?>
</form>
<?php


$apage->printFoot();
