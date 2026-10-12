<?php

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

use AA\IO\DB\DB_AA;
use AA\Security\SignedExpression;
use AA\Util\ChangeProposal;
use AA\Util\ChangesMonitor;

require_once __DIR__ . "/../include/config.php";
require_once __DIR__ . "/../include/locsess.php";
require_once __DIR__ . "/../include/util.php";
require_once __DIR__ . "/../include/item.php";
require_once __DIR__ . "/../include/view.php";
require_once __DIR__ . "/../include/locsess.php";
require_once __DIR__ . "/../include/itemfunc.php";

// every branch changes items or their change proposals - an anonymous request gets the login form
pageOpen();


/** not quoted, G() will also return paramaters from SSI, it should do also input checks in future */
class AA_V
{
    public static function P($var)
    {
        return $_POST[$var];
    }
    public static function G($var)
    {
        return $_GET[$var];
    }
    public static function C($var)
    {
        return $_COOKIE[$var];
    }
    public static function Parr(): array
    {
        return $_POST;
    }
}

/** Writes go past storeItem() and its permission check, so only someone who may edit all items of the slice. */
function ProposeCheckPerm(string $slice_id): void
{
    if (($slice_id === '') || !IfSlPerm(PS_EDIT_ALL_ITEMS, $slice_id)) {
        http_response_code(403);
        exit;
    }
}

/** the slice of a long item id, '' for none */
function ProposeSliceOf($item_id): string
{
    $item = (is_string($item_id) && is_long_id($item_id)) ? AA_Item::getItem(new zids($item_id, 'l')) : null;
    return $item ? (string) $item->getSliceID() : '';
}

/** field_content is AA_Value object */
function UpdateFieldContent($item_id, $field_id, $field_content, $flags = []): void
{
    ProposeCheckPerm(ProposeSliceOf($item_id));
    $changes       = new ChangesMonitor();
    $changes->addHistory([new ChangeProposal($item_id, $field_id, [GetRepreValue($item_id, $field_id)])]);

    UpdateField($item_id, $field_id, $field_content, $flags);
}

function GetRepreValue($item_id, $field_id, $alias_name = ''): string
{
    // get the item directly from the database
    $item = AA_Item::getItem(new zids($item_id));
    // alias_name comes from the browser: {ajax} sends what AA signed, older pages a bare alias name
    $alias_name = (string) $alias_name;
    $expression = preg_match('/^[0-9A-Z_]{8}$/', $alias_name) ? '_#' . $alias_name : (($alias_name === '') ? null : SignedExpression::fromRequest($alias_name, 'alias_name', SignedExpression::fieldsOf($item)));
    $repre_value = is_null($expression) ? $item->f_h($field_id) : $item->subst_alias($expression);
    return strlen($repre_value) ? $repre_value : '--';
}


/** AA\IO\AbstractGrabber\AbstractGrabber\Form - Grabs data POSTed by AA form
*/
class GrabberAssignmentform extends AA\IO\Grabber\AbstractGrabber
{
    public $_items = [];            /** list if files to grab - internal array */
    public $_index;              /**  */

    public function __construct() {}

    /** Name of the grabber - used for grabber selection box */
    public static function name(): string
    {
        return _m('Assignment form');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in HTML
     */
    public static function description(): string
    {
        return _m("Grabs item relations data POSTed by form");
    } // snerovadlo

    /** Possibly preparation of grabber - it is called directly before getItem()
    *  method is called - it means "we are going really to grab the data
    */
    public function prepare(): void
    {
        foreach (AA_V::Parr() as $varname => $varvalue) {
            if (strlen($varname) > 20 && str_starts_with($varname, 'asids')) {
                $item_id       = substr($varname, 5);
                $ids     = explode('~', AA_V::P('asids' . $item_id));
                $weights = explode('~', AA_V::P('asweights' . $item_id));
                $notes   = explode('~', AA_V::P('asnotes' . $item_id));
                $types   = explode('~', AA_V::P('astypes' . $item_id));

                foreach ($ids as $i => $related_id) {
                    if ($related_id) {
                        $item = new ItemContent();
                        $item->setValue('relation........', $item_id);
                        $item->setValue('relation.......1', $related_id);
                        $item->setValue('relation.......2', AA_V::P('assourceplan'));
                        $item->setValue('relation.......3', AA_V::P('asdestplan'));
                        $item->setValue('number..........', $weights[$i]);
                        $item->setValue('text............', $notes[$i]);
                        $item->setValue('category........', $types[$i]);
                        $this->_items[] = $item;
                    }
                }
            }
        }
        $this->_index = 0;
        reset($this->_items);   // go to first long id
    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    public function getItem(): ?AA_Content
    {
        if (!($item = $this->_items[$this->_index])) {
            return null;
        }
        $this->_index++;
        return $item;
    }

    public function finish(): void
    {
        $this->_items = [];
    }
}


// BSC assignment - snerovadlo 1
if (AA_V::P('bsc') == 1) {
    $updated = 0;
    // all items checked first, so a refusal never leaves the form half stored
    foreach (AA_V::Parr() as $varname => $varvalue) {
        if (strlen($varname) > 20 && str_starts_with($varname, 'bsc')) {
            ProposeCheckPerm(ProposeSliceOf(substr($varname, 3)));
        }
    }
    foreach (AA_V::Parr() as $varname => $varvalue) {
        if (strlen($varname) > 20 && str_starts_with($varname, 'bsc')) {
            $item_id = substr($varname, 3);
            $field_content = new AA_Value();
            //            foreach ( explode(',', $varvalue) as $related_id ) {   // older  - Honza 17.8.2016
            foreach (explode('-', $varvalue) as $related_id) {
                if ($related_id) {
                    $field_content->addValue($related_id);
                }
            }
            // we do not use special field now - we just assign the SA and
            // Aktivity as normal (but form foreign plan)
            UpdateFieldContent($item_id, 'relation........', $field_content, ItemContent::FLAG_JUST_STORE);
            ++$updated;
        }
    }
    echo "Upraveno $updated zaznamu";
}
// Snerovadlo assignment
elseif (AA_V::P('assignment') == 1) {
    $updated = 0;
    ProposeCheckPerm('de6a767322ed6040d4b745f5c16a7683');
    if (AA_V::P('assourceplan') && AA_V::P('asdestplan')) {

        $set = new AA_Set('de6a767322ed6040d4b745f5c16a7683');
        $set->addCondition(new AA_Condition('relation.......2', '==', AA_V::P('assourceplan')));
        $set->addCondition(new AA_Condition('relation.......3', '==', AA_V::P('asdestplan')));
        $zids = $set->query();

        if (!$zids->isEmpty()) {
            $now  = now();

            $SQL  = "UPDATE item SET status_code = '3', last_edit   = '$now', edited_by   = '" . quote(AuthGetUid() ?: '9999999999') . "'";
            $SQL .= " WHERE " . $zids->sqlin('id');

            DB_AA::sql($SQL);
        }

        $grabber      = new GrabberAssignmentform();
        $saver        = new AA\IO\Saver($grabber, null, 'de6a767322ed6040d4b745f5c16a7683', 'insert_if_new', 'new');
        $saver->run();

        AA::Pagecache()->invalidateFor("de6a767322ed6040d4b745f5c16a7683");  // invalidate old cached values
        echo "Uloženo";
    } else {
        echo "Nic neulozeno";
    }
}
// new approach using standard AA widgets and form variables
/*elseif ($_POST['aaaction']==='DOCHANGE') {

    [$item_id, $field_id] = AA\FormArray::parseId4Form($_POST['input_id']);
    $item   = AA_Item::getItem(new zids($item_id));
    $slice  = AA_Slice::getModule($item->getSliceId());

    // Use right language (from slice settings) - languages are used for button texts, ...
    $lang    = $slice->getLang();
    $charset = $slice->getCharset();   // like 'windows-1250'
    mgettext_bind($lang, 'output');

    $encoder       = new ConvertCharset;
    $field_content = new AA_Value;
    // fill content array
    foreach ($_POST['content'] as $val) {
        $field_content->addValue($encoder->Convert($val, 'utf-8', $charset));
    }
    // preserve old flags
    $field_content->setFlag($item->getFlag($field_id));

    UpdateFieldContent($item_id, $field_id, $field_content);
    $changes = new AA\Util\ChangesMonitor();
    $changes->deleteProposalForSelector($item_id, $field_id);

    $repre_value = GetRepreValue($item_id, $field_id, $_POST['alias_name']);
    echo $encoder->Convert($repre_value, $charset, 'utf-8');
}
*/ elseif (AA_V::P('cancel_changes') && AA_V::P('item_id') && AA_V::P('field_id')) {
    ProposeCheckPerm(ProposeSliceOf(AA_V::P('item_id')));
    $changes = new ChangesMonitor();
    $changes->deleteProposalForSelector(AA_V::P('item_id'), AA_V::P('field_id'));
    $encoder = new ConvertCharset();
    $repre_value = GetRepreValue(AA_V::P('item_id'), AA_V::P('field_id'), AA_V::P('alias_name'));
    echo $encoder->Convert($repre_value, 'windows-1250', 'utf-8');
} elseif (AA_V::P('do_change') && AA_V::P('item_id') && AA_V::P('field_id')) {
    $encoder       = new ConvertCharset();
    $val           = $encoder->Convert(AA_V::P('content'), 'utf-8', 'windows-1250');
    UpdateFieldContent(AA_V::P('item_id'), AA_V::P('field_id'), new AA_Value($val));
    $changes = new ChangesMonitor();
    $changes->deleteProposalForSelector(AA_V::P('item_id'), AA_V::P('field_id'));
    $encoder = new ConvertCharset();
    $repre_value = GetRepreValue(AA_V::P('item_id'), AA_V::P('field_id'), AA_V::P('alias_name'));
    echo $encoder->Convert($repre_value, 'windows-1250', 'utf-8');
} elseif (AA_V::P('item_id') && AA_V::P('field_id')) {
    ProposeCheckPerm(ProposeSliceOf(AA_V::P('item_id')));
    $encoder = new ConvertCharset();
    $text = $encoder->Convert(AA_V::P('content'), 'utf-8', 'windows-1250');

    $changes = new ChangesMonitor();
    $changes->addProposal([new ChangeProposal(AA_V::P('item_id'), AA_V::P('field_id'), [$text])]);
    echo $encoder->Convert($text, 'windows-1250', 'utf-8');
} elseif (AA_V::P('change_id')) {
    $changes = new ChangesMonitor();

    // changes_arr[item_id] = [[field_id] => [0 => value1, 1 => value2, ...]]
    $changes_arr = $changes->getProposalByID(AA_V::P('change_id'));
    foreach (array_filter(array_keys($changes_arr)) as $item_id) {
        ProposeCheckPerm(ProposeSliceOf($item_id));
    }
    foreach ($changes_arr as $item_id => $fids) {
        if ($item_id) {
            foreach ($fids as $fid => $values) {
                if ($fid) {
                    // $values are normal array, but it is OK for the AA_Value constructor
                    UpdateFieldContent($item_id, $fid, new AA_Value($values));
                }
            }
        }
    }
    $changes->deleteProposalForSelector($item_id, $fid);

    $content4id    = new ItemContent($item_id);
    $encoder = new ConvertCharset();
    echo $encoder->Convert($content4id->getValue($fid), 'windows-1250', 'utf-8');
}

exit;
