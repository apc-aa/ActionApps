<?php
/**
 * Importing CSV data (CSV stands for Comma Separated Value)
 *
 * The process of importing CSV data is splitted in the two parts:
 *   1) Converting CSV data to list of items, where each item is stored to the
 *      ItemContent class . See ItemContent.php3
 *   2) Each item in the ItemContent class is transformed according
 *      to the transformation actions to another ItemContent, which will be
 *      stored to some slice.
 *
 * Sources of the data can be : file, url or text.
 *
 * Input:
 *    $slice_id for edit slice
 *    optionaly $Msg to show under <h1>Headline</h1>
 *
 * There are two modes:
 *     default - shows a form for setting a source of the csv data
 *               and parameters of the conversion
 *     upload  - uploads csv data to a temporary file in the APC-AA server
 *               and continues with setting transformation actions
 *
 *  $dataType - source of data: should contain: "file", "url" or "text"
 *              (default "file")
 *  $url      - url of csv data applied when $dataType == "url"
 *  $upfile   - applied when $dataType == "file"
 *  $text     - applied when $dataType == "text"
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Ondrej Mazanec, Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/


// todo - work with global variable $err??

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/import_util.php3";

define("FILE_PREFIX", 'importdata');

$text      = $_REQUEST['text'];
$upfile    = $_REQUEST['upfile'];
$url       = $_REQUEST['url'];
$enclosure = $_REQUEST['enclosure'];
$delimiter = $_REQUEST['delimiter'];

if (!IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    MsgPage(StateUrl(self_base()."index.php3"), _m("You have not permissions to import files"));
    exit;
}

if (!isset($slice_id)) {
    MsgPage(StateUrl(self_base()."index.php3"), _m("Missing slice"));
    exit;
}

if (!isset($dataType)) {
    $dataType = "file";
}

$slice = AA_Slice::getModule($slice_id);

// Upload a data to the server. The file name is generated automaticly
// by unique id function. The path is upload_directory/csv_data.
// Delete old csv data in the upload_directory/csv_data.

if ($upload OR $preview) {

    // create unique file name
    unset($err);
    $file_name  = Files::getTmpFilename(FILE_PREFIX);

    switch ($dataType) {
        case 'file':
            if ( $_FILES['upfile']['name'] != '' ) {
                // upload file - todo: error is not returned, if not exist
                $dest_file = Files::uploadFile('upfile', Files::destinationDir($slice), '', 'overwrite', $file_name);
                if ($dest_file === false) {   // error
                    $err[] = Files::lastErrMsg();
                }
            } else {
                $dest_file = $previous_upload;
            }
            break;
        case 'url':
            if ( ($handle = fopen($url, 'r')) === false ) {
                $err[] = _m('Cannot read input url');
            } else {
                fclose($handle);
                $dest_file = $url;
            }
            break;
        default:
            $dest_file = Files::createFileFromString($text, Files::destinationDir($slice), $file_name);
            if ($dest_file === false) {   // error
                $err[] = Files::lastErrMsg();
            }
    }
    if ($err != "") {
         MsgPage(StateUrl(self_base()."se_csv_import.php3"), $err);
    }
}

if ($upload) {
    // delete files older than one week in the img_upload directory
    Files::deleteTmpFiles(FILE_PREFIX, $slice);

    // create array of additional csv parameters
    $addParams['enclosure'] = $enclosure;
    $addParams['delimiter'] = $delimiter == '\t' ? chr(9) : $delimiter;
    $addParams['caption']   = $caption;

    // continue with settings transformation actions
    go_url(StateUrl(self_base()."se_csv_import2.php3"). "&fileName=".urlencode($dest_file)."&slice_id=$slice_id&addParamsSerial=" .bin2url(serialize($addParams)));
}

require_once __DIR__."/../include/formutil.php3";


$apage = new AA_Adminpageutil('sliceadmin','CSVimport');
$apage->setTitle(_m("Admin - Import CSV (1/2) - Source data"));
$apage->setForm(['enctype'=>'multipart/form-data']);
$apage->printHead($err, $Msg);

if ($preview) { // file preview
    if (!($handle = fopen($dest_file, "r"))) {
        $err = _m("Cannot open a file for preview");
    } else {
        FrmTabCaption(_m("File preview"));

        $numRows = IMPORTFILE_PREVIEW_ROWS; // number of showed items(rows)
        $csvRec = getCSV($handle,$delimiter,$enclosure);
        if (!$caption) {
            for ( $i=0, $ino=count($csvRec); $i<$ino; ++$i) {
                $caption[] = "Field ".($i+1);
            }
            FrmTabRow($caption);
            FrmTabRow($csvRec);
            $numRows--;
        } else {
            FrmTabRow($csvRec);
        }
        while ($numRows-- > 0) {
            $csvRec = getCSV($handle,$delimiter,$enclosure);
            if (!$csvRec) {
                break;
            }
            FrmTabRow($csvRec);
        }
        FrmTabEnd();
        fclose($handle);
    }
    if ($err) {
        huhl($err);
    }
}
?>
<table width="600" border="0" cellspacing="0" cellpadding="1"
       bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
    <tr><td class="tabtit"><b>&nbsp;<?php echo _m("CSV format settings") ?></b></td></tr>

    <tr><td>
      <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
      <?php
          $arr = [
              ','  => 'comma ,',
              ';'  => 'semicolon ;',
              '\t' => 'tabulator \t',
              '|'  => 'pipe |',
              '~'  => 'tilde ~',
              ''   => 'other'
         ];

      FrmInputPreSelect("delimiter", "Delimiter of fields", $arr, $preview ? $delimiter : ';' );
      $arr = [
          '"'  => 'double quote "',
          "'"  => 'single quote \''
      ];
      FrmInputPreSelect("enclosure", "Enclosure", $arr, $preview ? $enclosure : '"');

      FrmInputChBox("caption","Use first row as field names",$preview ? $caption : true);

      $options      = [
          'file' => _m('File'),
          'url'  => _m('URL'),
          'text' => _m('Text')
      ];
      $html_options = [
          'file' => _m('File'),
          'url'  => _m('URL'),
          'text' => _m('Text')
     ];

     //echo getSelectWithParam('dataType', $options, "", $html_options);
     ?>
      </table>
    </td></tr>

    <tr><td class="tabtit"><b>&nbsp;<?php echo _m("Source of CSV data") ?></b></td></tr>
    <tr><td>
            <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
                <tr>
                    <td><label><input type="radio" <?php if ($dataType == "file") echo "checked"; ?> name="dataType" value="file">&nbsp;file</label></td>
                    <td><input type="file" name="upfile" value="<?php echo $upfile_name ?>"></td>
                </tr>
                <tr>
                    <td><label><input type="radio" <?php if ($dataType == "url") echo "checked"; ?> name="dataType" value="url">&nbsp;URL</label></td>
                    <td><input type="text" name="url" value="<?php echo $url ?>" placeholder="https://" style="width: 98%;"></td>
                </tr>
                <tr>
                    <td><label><input type="radio" <?php if ($dataType == "text") echo "checked"; ?> name="dataType" value="text">&nbsp;text</label></td>
                    <td><textarea name="text" rows="5"><?php echo $text ?></textarea></td>
                </tr>
            </table>
        </td></tr>
    <tr><td align="center">
            <input type="hidden" name="previous_upload" value="<?php echo $dest_file ?>">
            <input type="hidden" name="slice_id" value="<?php echo $slice_id ?>">
            <input type="submit" name="preview" value="<?php echo _m("Preview")?>">
            <input type="submit" name="upload" value="<?php echo _m("Next") ?>" align="center">&nbsp;&nbsp;
        </td></tr>
  </table>

<?php
$apage->printFoot();
