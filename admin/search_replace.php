<?php
/**
 * Form displayed in popup window allowing search and replace item content
 * for specified items
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
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/transformation.class.php";

const SEARCH_REPLACE_PREFIX = 'transform';

try {
    // array ( )
    $aa = InputVars::arrayPOST('aa');
    // array ( '' => 2, )
    $aa_akce_all = InputVars::stringPOST('aa_akce_all'); // empty
    // array ( 'ModifyConte…' => 4, )
    $akce = InputVars::stringPOST('akce'); // alpha
    // array ( '' => 4, )
    $akce_param = InputVars::stringPOST('akce_param'); // empty
    // array ( )
    $chb = InputVars::arrayPOST('chb');
    // array ( 'subtitle...…' => 12, )
    $field_id = InputVars::stringPOST('field_id');
    // array ( 'Změnit' => 12, )
    $fill = InputVars::stringPOST('fill');
    // array ( 'sel_item' => 12, )
    $group = InputVars::stringPOST('group');
    // array ( 'ModifyConte…' => 4, )
    $markedaction_select = InputVars::stringPOST('markedaction_select'); // alpha
    // array ( 'on' => 12, )
    $silent = InputVars::boolPOST('silent');
    // array ( 'AA_Transfor…' => 12, )
    $transform = InputVars::stringPOST('transform');
    // $transform* // not necessary
    $tasks = InputVars::intPOST('tasks');
    // $transform* // not necessary
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}



if ( !IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    MsgPage(_m("You do not have permission to edit items this way in this slice"));
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
    $items     = $r_sr_state['items'];  // session variable holds selected items from item manager
    // we really want to fill fields
    $zids          = new zids;
    $updated_items = 0;
    $planned = 0;
    if ($_REQUEST[SEARCH_REPLACE_PREFIX] AND (strpos(strtolower($_REQUEST[SEARCH_REPLACE_PREFIX]), 'aa_transformation_')===0)) {   // strtolower is fix for php4, where case of any class is small
        $transformation = AA_Object::factory($_REQUEST[SEARCH_REPLACE_PREFIX],AA_Transformation::getRequestVariables(SEARCH_REPLACE_PREFIX, $_REQUEST[SEARCH_REPLACE_PREFIX]));
        $zids           = ( ($group == 'testitemgroup') ? new zids($testitem) : getZidsFromGroupSelect($group, $items, $searchbar) );

        $transformator  = new AA_Transformator($transformation, $field_id, null, $silent, ($silent AND ($field_id=='last_edit.......') AND IsSuperadmin()));
        if ($tasks>0) {
            $zids_chunked = $zids->chunk($tasks);
            $toexecute = new \AA\Later\Toexecute();

            foreach ($zids_chunked as $chunk) {
                if ($toexecute->later($transformator->setZids($chunk),[],'ModifyItemContent')) {
                    $planned += $chunk->count();
                }
            }
        } else {
            $updated_items = $transformator->setZids($zids)->transform();
        }
    }

    $Msg     = MsgOk(_m("Items selected: %1, Items successfully updated: %2, Items planned: %3", [$zids->count(), $updated_items, $planned]));
    if ((string)$group == "sel_item") {
        $sel = "LIST";
    } elseif ((string)$group == "testitemgroup") {
        $sel = "TEST";
    } else {
        $sel = get_if($group,"0");  // bookmarks groups are identified by numbers
    }
    AA_Log::write("ITEM_FIELD_FILLED", $sel, [$zids->count(), $updated_items, $planned]);
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '
  <link rel=StyleSheet href="'.AA_INSTAL_PATH.'tabledit.css"  title="TableEditCSS">
  <title>'.  _m("Modify items") .'</title>';
FrmJavascriptFile('javascript/inputform.min.js?v='.AA_JS_VERSION);
IncludeManagerJavascript();
echo '
</head>
<body>
  <h1>'. _m("Modify items") .'</h1>
  <form name="modifyitemsform" method="post">';

echo GetPrintArray($err);
echo $Msg;

$slice  = AA_Slice::getModule(AA::$module_id);

if ( !IsSuperadmin() ) {
    $restricted_fields = [
        'slice_id........' => true,
                                'id..............' => true,
                                'short_id........' => true
    ];
} else {
    $restricted_fields = ['short_id........' => true];
}

// create field_select and field_copy_arr arrays for field selection
$field_select['no_field'] = _m('Select field...');
$field_copy_arr           = [];
$fields                   = $slice->getFields()->getNameArray();
foreach ($fields as $fld_id => $fld_name) {
    if ( !$restricted_fields[$fld_id] ) {
        $field_select[$fld_id] = "$fld_name ($fld_id)";
    }
    $field_copy_arr[$fld_id] = "$fld_name ($fld_id)";
}

$params = [
    'field_copy_arr' => $field_copy_arr,
    'field_select'   => $field_select
];

FrmTabCaption((count($items) ? _m("Items") : (_m("Stored searches for ") . $slice->getName())));

$messages['view_items']     = _m("View items");
$messages['selected_items'] = _m('Selected items');
FrmItemGroupSelect( $items, $searchbar, $group, 'items', $messages);

FrmTabSeparator(_m('Fill field'));
FrmInputSelect('field_id',       _m('Field'),             $field_select,       $field_id, true,
               _m('Be very careful with this. Changes in some fields (Status Code, Publish Date, Slice ID, ...) could be very crucial for your item\'s data. There is no data validity check - what you will type will be written to the database.<br>You should also know there is no UNDO operation (at least now).'));

FrmInputChBox('silent', _m('Silent'), true, false, "", 1, false, _m('Just update the field and do not perform any other operations like set-last-edit, evaluate-computed-field, feed, ...'));

FrmStaticText(_m('Action'), AA_Components::getSelectionCode('AA_Transformation_', SEARCH_REPLACE_PREFIX, $params), "", "", false);

FrmInputNumber('tasks', _m('Run as Tasks - fill number of items in one task'), $tasks, 0, 99999999, false, _m('If you want to run this operation as tasks, enter the number of items in one task here. The operation will be split into tasks. The tasks will be run in the background. You can check the status of the tasks in the task manager.'));

FrmTabEnd([
    'fill' => ['type'=>'submit', 'value'=>_m('Fill')],
    'close'=> ['type'=>'button', 'value'=>_m('Close'), 'add'=>'onclick="window.close()"']
]);

// list selected items to special form - used by manager.min.js to show items (recipients)
echo "\n  </form>";
FrmItemListForm($items);
echo "\n  </body>\n</html>";
page_close();

