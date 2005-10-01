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

/** Setting the transformation actions for converting an input ItemContent
 *  to an output ItemContent
 *
 * Parameters:
 * Input:
 *   $slice_id        - for edit slice
 *   $fileName        - file in upload directory
 *   $addParamsSerial - serialized additional parameters (file type specific)
 */


require_once "../include/init_page.php3";
require_once $GLOBALS['AA_INC_PATH']."util.php3";
require_once $GLOBALS['AA_INC_PATH']."import_util.php3";
require_once $GLOBALS['AA_INC_PATH']."constants_param_wizard.php3";
require_once $GLOBALS['AA_INC_PATH']."formutil.php3";
require_once $GLOBALS['AA_INC_PATH']."feeding.php3";

/** Returns key of the $array, which value is most similar to given $text */
function findNearestText($text, $array) {
    $max = -2;
    if ( isset($array) AND is_array($array) ) {
        $text = strtoupper($text);
        foreach ( $array as $k => $v ) {
            $distance = levenshtein( $text, strtoupper($v) );
            if ( ($max == -2) OR ($distance < $max) ) {
                $max = $distance;
                $ret = $k;
            }
        }
    }
    return $ret;
}


if (!IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    MsgPage($sess->url(self_base()."index.php3"), _m("You have not permissions to setting "));
    exit;
}

$err["Init"] = "";         // error array (Init - just for initializing variable

// check parameters
if (!file_exists($fileName)) {
    MsgPage($sess->url(self_base()."se_csv_import.php3"), _m("File for import does not exists:").$fileName );
}

$addParams = unserialize(base64_decode($addParamsSerial));
if (!$addParams) {
    MsgPage($sess->url(self_base()."se_csv_import.php3"), _m("Invalid additional parameters for import"));
}

if (!isset($itemId)) {
    $itemId = "new";
}

if (!isset($actionIfItemExists)) {
    $actionIfItemExists = STORE_WITH_NEW_ID;
}

//-----------------------------------------------------------------------------
// Output items should contain just these slice fields
list($slice_fields,$fields_priorities) = GetSliceFields($slice_id);

if ($upload || $preview) {

    // create actions from the form
    $actions['slice_id........'] = "value";
    $params['slice_id........']  = $slice_id;

    $actions['id..............'] = $itemId;
    if ($itemId == 'old') {
        // update items with the id specified in $itemIdMappedFrom field
        $mapping['id..............'] = $itemIdMappedFrom;
        $actions['id..............'] = $itemIdMappedActions;
        $params['id..............']  = $itemIdMappedParams;
    } elseif ( $itemId == 'new' ) {
        $actions['id..............'] = 'new';  // new_id
    }

    $trans_actions = new Actions($actions,$mapping, $html, $params);

    // Create list of fields from the first row of csv data
    $fieldNames = createFieldNames($fileName,$addParams);
}

//upload mode
if ($upload) {
    global $db;
    set_time_limit(IMPORTFILE_TIME_LIMIT);	// set time for the executing this script : todo ???

    $handle       = fopen($fileName,"r");
    $numProcessed = 0;
    $numError     = 0;

    // if first row is used for field names, skip it
    if ($addParams['caption']) {
        getCSV($handle,CSVFILE_LINE_MAXSIZE,$addParams['delimiter'],$addParams['enclosure']);
    }

    while ($csvRec = getCSV($handle,CSVFILE_LINE_MAXSIZE,$addParams['delimiter'],$addParams['enclosure'])) {
        $err = convertCSV2Items($csvRec,$fieldNames,$trans_actions,$slice_fields,$itemContent);
        $numProcessed++;
        $msg .= _m("Item:").$numProcessed .":";

        if (!$err) {
            $itemContent->setSliceID($slice_id);
            $added_to_db = $itemContent->storeItem($actionIfItemExists, false);     // not invalidate cache
            if ($added_to_db == false) {
                $err = _m("Cannot store item to DB"). ' '. ItemContent::LastErrMsg();
            }
        }
        if ($err) {
            $numError++;
            $msg.= _m("Transformation error:"). $err . "not inserted";
        } else {
            $msg.= _m('Ok: Item %1 stored', array($added_to_db));
        }
        $msg .= "<br>\n";
    }
    // log
    $logMsg = "Slice " .$slice_id. ": Processed ". $numProcessed. ", Stored ". ($numProcessed-$numError) .", Error: ". $numError. " items";
    writeLog("CSV_IMPORT",$logMsg);

    // invalidate cache;
    $GLOBALS['pagecache']->invalidateFor("slice_id=".$GLOBALS['slice_id']);  // invalidate old cached values

    fclose($handle);

    // deletes  uploaded file, todo - uncomment
    if (unlink($fileName)) {
        writeLog("CSV_IMPORT",_m("Ok : file deleted "). $fileName );
    } else {
        writeLog("CSV_IMPORT",_m("Error: Cannot delete file"). $fileName );
    }

    $msg = _m("Added to slice"). $slice_id ." :<br><br>\n". $msg." <br><br>\n";
    MsgPage($sess->url(self_base()."se_csv_import.php3"), $msg.$logMsg );
}

//----------------------------------------------------------------------------
// Create output fields
foreach ( $fields_priorities as $v ) {
   if ($v != "slice_id........")
        $outFields[$v] = $slice_fields[$v]['name'];
}

//create list of actions, : todo : possible loading from a file
$actionList = getActions();

// Create input fields from the first row of CSV data
$inFields = createFieldNames($fileName,$addParams);

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<TITLE><?php echo _m("Admin - Import .CSV file"); ?></TITLE>
<SCRIPT Language="JavaScript"><!--
function InitPage() {}

/* Calls the parameters wizard. Parameters are as follows:
    list = name of the array containing all the needed data for the wizard
    combo_list = a combobox of which the selected item will be shown in the wizard
    text_param = the text field where the parameters are placed
  */
  function CallParamWizard(list, combo_list, text_param ) {
    page = "<?php echo $sess->url(self_base()."param_wizard.php3")?>"
        + "&list=" + list + "&combo_list=" + combo_list + "&text_param=" + text_param;
    combo_list_el = document.f.elements[combo_list];
    page += "&item=" + combo_list_el.options [combo_list_el.selectedIndex].value;
    param_wizard = window.open(page,"somename","width=450,scrollbars=yes,menubar=no,hotkeys=no,resizable=yes");
    param_wizard.focus();
  }

//-->
</SCRIPT>
</HEAD>
<BODY>
<?php
  $useOnLoad = true;
  require_once $GLOBALS['AA_INC_PATH']."menu.php3";
  showMenu ($aamenus, "sliceadmin","CSVimport");
  PrintArray($err);
  echo stripslashes($Msg);
  echo "<H1><B>" . _m("Admin - Import CSV (2/2) - Mapping and Actions") . "</B></H1>";

if ($preview) {
    $slf['id..............'] = "Item id";
    foreach ( $slice_fields as $k => $v ) {
        $slf[$k] = $v['name'];
    }

    $handle = fopen($fileName,"r");

    FrmTabCaption(_m("Mapping preview"));
    FrmTabRow($slf);			// print output fields

    // if the first row is used for field names, skip it
    if ($addParams['caption'])
        getCSV($handle,CSVFILE_LINE_MAXSIZE,$addParams['delimiter'],$addParams['enclosure']);

    $numRows=5;		// number of showed items(rows) in the table
    while ($numRows-- > 0) {
        $csvRec = getCSV($handle,CSVFILE_LINE_MAXSIZE,$addParams['delimiter'],$addParams['enclosure']);
        if (!$csvRec)		// end of file
            break;
        $err = convertCSV2Items($csvRec,$fieldNames,$trans_actions,$slice_fields,$itemContent);
        if ($err) {
            echo "<tr><td>Transformation error: $err </td></tr>";	// todo
        }
        $itemContent->showAsRowInTable($slf);
    }
    FrmTabEnd("");
    // end preview
}

$form_buttons = array("preview"         => array( "type"      => "submit",
                                                  "value"     => _m("Preview"),
                                                  "accesskey" => "P"),
                      "upload"          => array( "type"      => "submit",
                                                  "value"     => _m("Finish"),
                                                  "accesskey" => "S"),
                      "fileName"        => array( "value"     => $fileName ),
                      "addParamsSerial" => array( "value"     => $addParamsSerial )
                     );


echo '<form enctype="multipart/form-data" method=post name="f" action="'. $sess->url(self_base() . "se_csv_import2.php3") .'">';

FrmTabCaption(_m("Mapping settings"));
?>
    <tr>
      <td class=tabtxt><b><?php echo _m("To") ?></b></td>
      <td class=tabtxt><b><?php echo _m("From") ?></b></td>
      <td class=tabtxt><b><?php echo _m("Action") ?></b></td>
      <td class=tabtxt><b><?php echo _m("Html") ?></b></td>
      <td class=tabtxt><b><?php echo _m("Action parameters") ?></b></td>
      <td class=tabtxt><b><?php echo _m("Parameter wizard") ?></b></td>
     </tr>

       <?php
       $inFields["__empty__"] = "     ";
       foreach ( $outFields as $f_id => $f_name) {
           echo "<tr><td class=tabtxt><b>$f_name</b></td>\n";
           echo "<td>";
           FrmSelectEasy("mapping[$f_id]",$inFields,$preview ? $mapping[$f_id] : findNearestText($f_name, $inFields));		// todo - multiple
           echo "</td>";
           echo "<td class=tabtxt>";
           FrmSelectEasy("actions[$f_id]",$actionList,$preview ? $actions[$f_id] : "default");
           echo "</td>";

           echo "<td class=tabtxt ><input type=checkbox name=\"html[$f_id]\" "; if ($preview && $html[$f_id]) echo  "CHECKED";  echo  "></input></td>";
           echo "<td class=tabtxt><input type=text name=\"params[$f_id]\" value=\""; if ($preview) echo stripslashes($params[$f_id]);  echo "\"></input></td>";
           echo "<td class=tabhlp><a href='javascript:CallParamWizard(\"TRANS_ACTIONS\",\"actions[$f_id]\",\"params[$f_id]\")'><b>"
           ._m("Help: Parameter Wizard")."</b></a></td>";
           echo "</tr>\n";
       }
       FrmTabSeparator(_m("Import options"));
       ?>

       <tr><td class=tabtxt colspan=2>Setting item id:</td><tr>

       <tr><td class=tabtxt align=center><input type="radio" <?php if ($itemId == "new") echo "CHECKED"; ?> NAME="itemId" value="new"></td>
        <td class=tabtxt >Create new id</td>
       </tr>
       <tr>
       <td class=tabtxt align=center><input type="radio" <?php if ($itemId == "old") echo "CHECKED"; ?> NAME="itemId" value="old"></td>
       <td class=tabtxt ><?php
         echo _m("Map item id from"). '&nbsp';
         FrmSelectEasy("itemIdMappedFrom",$inFields, $itemIdMappedFrom ? $itemIdMappedFrom : ( $preview ? $idFrom : $inFields[0]));
         echo '<br>';
         $mapping_options = array ( 'pack_id'   => _m('unpacked long id (pack_id)'),
                                    'store'     => _m('packed long id (store)'),
                                    'string2id' => _m('string to be converted (string2id) - with param:'));

         FrmSelectEasy("itemIdMappedActions",$mapping_options, $preview ? $itemIdMappedActions : 'pack_id');
         echo '&nbsp<input type="text" name="itemIdMappedParams" value="'. ($preview ? $itemIdMappedParams : '').'"></input>';
       ?></td>
    </tr>

    <?php
    FrmTabSeparator(_m("Select, how to store the items"));
    $storage_mode = array('insert_if_new' => _m('Do not store the item'),
                          'insert_new'    => _m('Store the item with new id'),
                          'overwrite'     => _m('Update the item (overwrite)'),
                          'add'           => _m('Add the values in paralel to current values (the multivalues are stored, where possible)'),
                          'update'        => _m('Rewrite only the fields, for which the action is defined')
                          );
    FrmInputRadio('actionIfItemExists', _m('If the item id is already in the slice'), $storage_mode, $preview ? $actionIfItemExists : "insert_if_new", true, '', '', 1);
    FrmTabEnd($form_buttons, $sess, $slice_id);
    echo "</FORM>";
    HtmlPageEnd();
    page_close();
?>

