<?php
/**
 * Form displayed in popup window allowing search and replace item content
 * for specified items
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @param $chb[] - array of selected users (in reader management slice)
 */
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

require_once "../include/init_page.php3";
require_once $GLOBALS['AA_INC_PATH']. "formutil.php3";
require_once $GLOBALS['AA_INC_PATH']. "searchbar.class.php3";
require_once $GLOBALS['AA_INC_PATH']. "varset.php3";

function ChangeContent($zids, $field_id, $new_content) {
    global $allknownslices;

    $count = 0;  // number of updated items
    for ( $i=0; $i<=$zids->count(); $i++ ) {
        $item = GetItemFromId($zids->zid($i));
        if ( !$item ) {
            continue;
        }

        $content4id[$field_id][0]['value'] = addslashes($item->subst_alias($new_content));
        $slice_id = $item->getSliceID();
        $slice    =& $allknownslices->addslice($slice_id);
        $slices2invalidate[$slice_id] = $slice_id;
        if ( StoreItem($item->getItemID(), $slice_id, $content4id, $slice->fields('record'), false, false, false, $item->getContent())) { // update, invalidate, notfeed
            $count++;
        }
    }
    if (is_array($slices2invalidate)) {
        foreach($slices2invalidate as $slice_id) {
            $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");
        }
    }
    return $count;
}



$searchbar = new searchbar();   // mainly for bookmarks
$items=$chb;

if ( !$fill ) {               // for the first time - directly from item manager
    $sess->register('r_sr_state');
    unset($r_sr_state);       // clear if it was filled
    $r_sr_state['items'] = $items;
    // init variable settings goes here
} else {
    $items     = $r_sr_state['items'];  // session variable holds selected items fmom item manager
    if ( $fill ) {    // we really want to fill fields
        do {
            ValidateInput("field_id",    _m("Field"),       $field_id,    $err, true,  "text");
            ValidateInput("new_content", _m("New content"), $new_content, $err, false, "text");

            if ( count($err) > 1) break;

            // --- fill the fileds
            $zids    = ( ($group == 'testitemgroup') ?
                        new zids($testitem) : getZidsFromGroupSelect($group, $items, $searchbar) );
            $changed = ChangeContent($zids, $field_id, $new_content);
            $Msg     = MsgOK(_m("Items selected: %1, Items sucessfully updated: %2",
                                               array($zids->count(), $changed)));
            if ((string)$group == (string)"sel_item") {
                $sel = "LIST";
            } elseif ((string)$group == (string)"testitemgroup") {
                $sel = "TEST";
            } else {
                $sel = get_if($group,"0");  // bookmarks groups are identified by numbers
            }
            writeLog("ITEM_FIELD_FILLED", array($zids->count(), $changed),$sel);
        } while (false);
    }
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

echo '
  <link rel=StyleSheet href="'.$AA_INSTAL_PATH.'tabledit.css" type="text/css"  title="TableEditCSS">
  <title>'.  _m("Modify items") .'</title>';
IncludeManagerJavascript();
echo '
</head>
<body>
  <h1>'. _m("Modify items") .'</h1>
  <form name="modifyitemsform">';

PrintArray($err);
echo $Msg;

$slice  = new slice($slice_id);

if ( !IsSuperadmin() ) {
    $restricted_fields = array( 'slice_id........' => true,
                                'id..............' => true,
                                'short_id........' => true );
} else {
    $restricted_fields = array( 'short_id........' => true );
}

// create filed_select array for field selection
$fields = $slice->fields('record');
foreach ($fields as $fld_id => $fld) {
    if ( !$restricted_fields[$fld_id] ) {
        $field_select[$fld_id] = $fld['name']. ' ('. $fld_id. ')';
    }
}

FrmTabCaption( (is_array($items) ? _m("Items") : ( _m("Stored searches for ").$slice->name()) ));

$messages['view_items']     = _m("View items");
$messages['selected_items'] = _m('Selected items');
FrmItemGroupSelect( $items, $searchbar, 'items', $messages, $additional);

FrmTabSeparator( _m('Fill field') );
FrmInputSelect('field_id',       _m('Field'),             $field_select,       $field_id, true,
               _m('Be very carefull with this. Changes in some fields (Status Code, Publish Date, Slice ID, ...) could be very crucial for your item\'s data. There is no data validity check - what you will type will be written to the database.<br>You should also know there is no UNDO operation (at least now).'));
FrmTextarea(   'new_content', _m('New content'),       dequote($new_content),  12, 80, true,
               _m('You can use also aliases, so the content "&lt;i&gt;{abstract........}&lt;/i&gt;&lt;br&gt;{full_text......1}" is perfectly OK'));

FrmTabEnd(array( 'fill' =>array('type'=>'submit', 'value'=>_m('Fill')),
                 'close'=>array('type'=>'button', 'value'=>_m('Close'), 'add'=>'onclick="window.close()"')),
          $sess, $slice_id);

// list selected items to special form - used by manager.js to show items (recipients)
echo "\n  </form>";
FrmItemListForm($items);
echo "\n  </body>\n</html>";
page_close();
?>


