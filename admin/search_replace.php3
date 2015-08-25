<?php
/**
 * Form displayed in popup window allowing search and replace item content
 * for specified items
 *
 * PHP versions 4 and 5
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
 * @link      http://www.apc.org/ APC
 *
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH. "formutil.php3";
require_once AA_INC_PATH. "searchbar.class.php3";
require_once AA_INC_PATH. "varset.php3";
require_once AA_INC_PATH. "transformation.class.php3";

define('SEARCH_REPLACE_PREFIX', 'transform');


if ( !IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    MsgPage($sess->url(self_base())."index.php3", _m("You do not have permission to edit items this way in this slice"));
    exit;
}

$searchbar = new AA_Searchbar();   // mainly for bookmarks
$items=$chb;

if ( !$fill ) {               // for the first time - directly from item manager
    $sess->register('r_sr_state');
    unset($r_sr_state);       // clear if it was filled
    $r_sr_state['items'] = $items;
    // init variable settings goes here
} else {
    $items     = $r_sr_state['items'];  // session variable holds selected items fmom item manager
    if ( $fill ) {    // we really want to fill fields
        $zids          = new zids;
        $updated_items = 0;
        if ($_REQUEST[SEARCH_REPLACE_PREFIX] AND (strpos(strtolower($_REQUEST[SEARCH_REPLACE_PREFIX]), 'aa_transformation_')===0)) {   // strtolower is fix for php4, where case of any class is small
            $transformation = AA_Components::factory($_REQUEST[SEARCH_REPLACE_PREFIX],AA_Transformation::getRequestVariables(SEARCH_REPLACE_PREFIX, $_REQUEST[SEARCH_REPLACE_PREFIX]));
            $zids           = ( ($group == 'testitemgroup') ? new zids($testitem) : getZidsFromGroupSelect($group, $items, $searchbar) );

            $transformator  = new AA_Transformator;
            $updated_items  = $transformator->transform($zids, $field_id, $transformation, $silent);
        }

        $Msg     = MsgOK(_m("Items selected: %1, Items sucessfully updated: %2", array($zids->count(), $updated_items)));
        if ((string)$group == (string)"sel_item") {
            $sel = "LIST";
        } elseif ((string)$group == (string)"testitemgroup") {
            $sel = "TEST";
        } else {
            $sel = get_if($group,"0");  // bookmarks groups are identified by numbers
        }
        AA_Log::write("ITEM_FIELD_FILLED", array($zids->count(), $updated_items),$sel);
    }
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '
  <link rel=StyleSheet href="'.AA_INSTAL_PATH.'tabledit.css" type="text/css"  title="TableEditCSS">
  <title>'.  _m("Modify items") .'</title>';
FrmJavascriptFile( 'javascript/inputform.js' );
IncludeManagerJavascript();
echo '
</head>
<body>
  <h1>'. _m("Modify items") .'</h1>
  <form name="modifyitemsform" method="post">';

PrintArray($err);
echo $Msg;

$slice  = AA_Slice::getModule($slice_id);

if ( !IsSuperadmin() ) {
    $restricted_fields = array( 'slice_id........' => true,
                                'id..............' => true,
                                'short_id........' => true );
} else {
    $restricted_fields = array( 'short_id........' => true );
}

// create field_select array for field selection
$field_select['no_field'] = _m('Select field...');
$fields                   = $slice->fields('record');
foreach ($fields as $fld_id => $fld) {
    if ( !$restricted_fields[$fld_id] ) {
        $field_select[$fld_id] = $fld['name']. ' ('. $fld_id. ')';
    }
}

// create field_copy_arr array for field selection
foreach ($fields as $fld_id => $fld) {
    $field_copy_arr[$fld_id] = $fld['name']. ' ('. $fld_id. ')';
}

$params = array('field_copy_arr' => $field_copy_arr,
                'field_select'   => $field_select);

FrmTabCaption( (is_array($items) ? _m("Items") : ( _m("Stored searches for ").$slice->name()) ));

$messages['view_items']     = _m("View items");
$messages['selected_items'] = _m('Selected items');
FrmItemGroupSelect( $items, $searchbar, 'items', $messages, $additional);

FrmTabSeparator( _m('Fill field') );
FrmInputSelect('field_id',       _m('Field'),             $field_select,       $field_id, true,
               _m('Be very carefull with this. Changes in some fields (Status Code, Publish Date, Slice ID, ...) could be very crucial for your item\'s data. There is no data validity check - what you will type will be written to the database.<br>You should also know there is no UNDO operation (at least now).'));

FrmInputChBox('silent', _m('Silent'), true, false, "", 1, false, _m('Just update the field and do not perform any other operations like set-last-edit, evaluate-computed-field, feed, ...'));

FrmStaticText(_m('Action'), AA_Components::getSelectionCode('AA_Transformation_', SEARCH_REPLACE_PREFIX, $params), true, "", "", false );

FrmTabEnd(array( 'fill' =>array('type'=>'submit', 'value'=>_m('Fill')),
                 'close'=>array('type'=>'button', 'value'=>_m('Close'), 'add'=>'onclick="window.close()"')),
          $sess, $slice_id);

// list selected items to special form - used by manager.js to show items (recipients)
echo "\n  </form>";
FrmItemListForm($items);
echo "\n  </body>\n</html>";
page_close();
?>
