<?php
/** se_import.php - feeding settings
 *   expected AA::$module_id for edit slice
 *   optionally $Msg to show under <h1>Headline</h1> (typically: Category update successful)
 *
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

// const  AA_REGISTER_GLOBALS = true;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";

try {
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

if (!IfSlPerm(PS_FEEDING)) {
    MsgPage(_m("You have not permissions to change feeding setting"));
    exit;
}

$err = [];          // error array (Init - just for initializing variable

              // lookup all slices without this one
$SQL        = "SELECT id, name FROM slice WHERE id<>'$p_slice_id' ORDER BY name";
$all_slices = GetTable2Array($SQL, 'unpack:id', 'name');

              // lookup export_to slice
$SQL        = "SELECT name, id FROM slice, feedperms WHERE slice.id=feedperms.to_id AND feedperms.from_id='$p_slice_id' ORDER BY name";
$export_to  = GetTable2Array($SQL, 'unpack:id', 'name');

              // lookup importable slice
$SQL        = "SELECT name, id FROM slice LEFT JOIN feedperms ON slice.id=feedperms.from_id
                WHERE (feedperms.to_id='$p_slice_id' OR slice.export_to_all=1) AND slice.id<>'$p_slice_id' ORDER BY name";
$importable = GetTable2Array($SQL, 'unpack:id', 'name');

              // lookup imported slices
$SQL        = "SELECT name, id FROM feeds, slice LEFT JOIN feedperms ON slice.id=feedperms.from_id
                WHERE slice.id=feeds.from_id
                  AND (feedperms.to_id='$p_slice_id' OR slice.export_to_all=1)
                  AND feeds.to_id='$p_slice_id' ORDER BY name";
$imported   = GetTable2Array($SQL, 'unpack:id', 'name');

              // lookup exported slices
$SQL        = "SELECT name, id FROM feeds, slice LEFT JOIN feedperms ON slice.id=feedperms.to_id
                WHERE slice.id=feeds.to_id
                  AND feeds.from_id='$p_slice_id' ORDER BY name";
$exported   = GetTable2Array($SQL, 'unpack:id', 'name');

              // lookup for inconcistence in the database
              // feeding into nonexistant database
$SQL        = "SELECT to_id FROM feeds LEFT JOIN slice ON feeds.to_id=slice.id
                    WHERE feeds.from_id='$p_slice_id' AND slice.id IS NULL";
$wrong_exp  = GetTable2Array($SQL, 'unpack:to_id', 'unpack:to_id');

              // export_to_all setting
$SQL        = "SELECT export_to_all FROM slice WHERE slice.id='$p_slice_id'";
$export_to_all = GetTable2Array($SQL, 'aa_first', 'export_to_all');




$script2run = 'InitPage();';
$headcode = '<script>
function ExportAllClick() {
  document.f.export_y.disabled=document.f.export_to_all.checked;
  document.f.export_n.disabled=document.f.export_to_all.checked;
}

function InitPage() {
  ExportAllClick()
}

function UpdateImportExport(slice_id) {
  var url = "'. StateUrl(self_base() . "se_import2.php") .'"
  url += "&slice_id=" + slice_id
  url += "&to_all=" + (document.f.export_to_all.checked ? \'1\' : \'0\')
  for (let i = 0; i < document.f.import_y.options.length; i++) {
    if (document.f.import_y.options[i].value != "0")    // imported slices
      url += "&I%5B" + i + "%5D=" + encodeURIComponent(document.f.import_y.options[i].value)
  }
  for (let i = 0; i < document.f.export_y.options.length; i++) {
    if (document.f.export_y.options[i].value != "0")    // exported to slices
      url += "&E%5B" + i + "%5D=" + encodeURIComponent(document.f.export_y.options[i].value)
  }
  window.location=url
}
</script>';

$apage = new AA_Adminpageutil('sliceadmin','import');
$apage->setTitle(_m("Admin - configure Content Pooling"));
$apage->addRequire($script2run, 'AA_Req_Load');
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->addRequire(get_aa_url('/javascript/js_lib.min.js?v='.AA_JS_VERSION, '', false));
$apage->printHead($err, $Msg);


$form_buttons = [
    "upd" => [
        "type"=>"button", "value"=>_m("Update"), "accesskey"=>"S",
                                      "add" =>"onClick=\"UpdateImportExport('".AA::$module_id."')\""
    ],
                       "cancel"=> ["url"=>"se_fields.php"]
];

FrmTabCaption(_m("Enable export to slice:"));
?>
<tr class="aa-tbl-header">
    <th width="45%"><?php echo _m("Export disable") ?></th>
    <th width="10%">&nbsp;</th>
    <th width="45%"><?php echo _m("Export enable") ?></th>
</tr>
<tr>
<td>
<select name="export_n" size="8">
  <?php
  if ( isset($export_to) AND is_array($export_to)) {
      foreach ($all_slices as $s_id => $name) {
          if ( $export_to[$s_id] == "" ) {
              echo "<option value=\"$s_id\"> $name </option>";
          }
      }
  } else {
      foreach ($all_slices as $s_id => $name) {
          echo "<option value=\"$s_id\"> $name </option>";
      }
  }
  ?>
</select></td>
<td><input type="button" value="  &#9654;  " onClick = "MoveSelected(document.f.export_n, document.f.export_y)">
    <br><br><input type="button" VALUE="  &#9664;  " onClick = "MoveSelected(document.f.export_y, document.f.export_n)"></td>
<td>
<select name="export_y" size="8" multiple>
  <?php
  if ( isset($export_to) AND is_array($export_to)) {
      foreach ($export_to as $s_id => $name) {
          echo "<option value=\"$s_id\"> $name </option>";
      }
  }    ?>
</select>
</td>
</tr>
<tr><td colspan="3"><table>
<?php
  FrmInputChBox("export_to_all", _m("Enable export to any slice"), $export_to_all, true, "OnClick=\"ExportAllClick()\"");
  if ( isset($exported) AND is_array($exported) ) {
      FrmStaticText(_m("Currently exported to"), join("<br>", $exported), '', '', false);
  }
  if ( isset($wrong_exp) AND is_array($wrong_exp) ) {
      FrmStaticText(_m("Wrong export (non existant slice!):"), join("<br>", $wrong_exp), '', '', false);
  }
?>
</table></td></tr>
<?php
  FrmTabSeparator(_m("Import from slice:"));
  /*
<tr><td colspan=3>&nbsp;</td></tr>
</table></tr></td>
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Import from slice:") ?></b></td></tr>
<tr><td>
<table>*/
?>
<tr>
    <th width="45%"><?php echo _m("Do not import") ?></th>
    <th width="10%">&nbsp;</th>
    <th width="45%"><?php echo _m("Import") ?></th>
</tr>
<tr>
<td>
<select name="import_n" size="8">
  <?php
  if ( isset($importable) AND is_array($importable)) {
      foreach ($importable as $s_id => $name) {
          if ( $imported[$s_id] == "" ) {
              echo "<option value=\"$s_id\"> $name </option>";
          }
      }
  }
  ?>
</select></td>
<td><input type="button" value="  &#9654;  " onClick = "MoveSelected(document.f.import_n,document.f.import_y)">
    <br><br><input type="button" value="  &#9664;  " onClick = "MoveSelected(document.f.import_y,document.f.import_n)"></td>
<td>
<select name="import_y" size="8">
  <?php
  if ( isset($imported) AND is_array($imported)) {
    foreach ($imported as $id => $name) {
        echo "<option value=\"$id\"> $name </option>";
    }
  }     ?>
</select>
</td>
</tr>
<?php

  FrmTabEnd($form_buttons);
  /*
<tr><td colspan=3>&nbsp;</td></tr>
</table></tr></td>
<tr><td>
<input type=hidden name="slice_id" value="<?php echo AA::$module_id ?>">
<input type="button" VALUE="<?php echo _m("Update") ?>" onClick = "UpdateImportExport('<?php echo AA::$module_id ?>')">&nbsp;&nbsp;
<input type=submit name=cancel value="<?php echo _m("Cancel") ?>">
</td></tr></table>*/

$apage->printFoot();
