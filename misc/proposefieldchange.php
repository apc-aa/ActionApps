<?php
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

require_once "../include/config.php3";
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."util.php3";

require_once AA_INC_PATH."item.php3";
require_once AA_INC_PATH."view.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."itemfunc.php3";
require_once AA_INC_PATH."convert_charset.class.php3";

//error_reporting(E_ERROR | E_PARSE);
//ini_set('display_errors',true);


function StripslashesDeep($value) {
    return is_array($value) ? array_map('StripslashesDeep', $value) : stripslashes($value);
}

if ( get_magic_quotes_gpc() ) {
    $_POST    = StripslashesDeep($_POST);
    $_GET     = StripslashesDeep($_GET);
    $_COOKIE  = StripslashesDeep($_COOKIE);
}

/** not quoted, G() will also return paramaters from SSI, it should do also input checks in future */ 
class AA_V {
    function P($var) { return $_POST[$var];   }
    function G($var) { return $_GET[$var];    }
    function C($var) { return $_COOKIE[$var]; }
    function Parr()  { return $_POST;         }
}

/** field_content is AA_Value object */
function UpdateFieldContent($item_id, $field_id, $field_content, $invalidate = true) {
    $changes       = new AA_ChangesMonitor();
    $changes->addHistory(new AA_ChangeProposal($item_id, $field_id, array(GetRepreValue($item_id, $field_id))));

    $content4id    = new ItemContent();
    $content4id->setByItemID($item_id, true);     // ignore password
    // if we do not ignore it, then whole item is destroyed for slices with slice_pwd

    $sli_id        = $content4id->getSliceID();
    unset($content4id);

    $newcontent4id = new ItemContent();
    $newcontent4id->setAaValue($field_id, $field_content);

    $newcontent4id->setItemID($item_id);
    $newcontent4id->setSliceID($sli_id);
    $updated_items = 0;
    if ($newcontent4id->storeItem( 'update_silent', array($invalidate, false))) {    // invalidatecache, not feed
        $updated_items++;
    }
}

function GetRepreValue($item_id, $field_id, $alias_name='') {
    // get the item directly from the database
    $item        = AA_Item::getItem(new zids($item_id));
    $repre_value = $alias_name ? $item->subst_alias('_#'.$alias_name) : $item->getval($field_id);
    return (strlen($repre_value) > 0) ? $repre_value : '--';
}

// BSC assignment
if ( AA_V::P('bsc') == 1 ) {
    $updated = 0;
    foreach (AA_V::Parr() as $varname => $varvalue) {
        if (strlen($varname)>20 AND substr($varname,0,3)=='bsc') {
            $item_id = substr($varname,3);
            $field_content = new AA_Value;
            foreach ( explode(',', $varvalue) as $related_id ) {
                if ( $related_id ) {
                    $field_content->addValue($related_id);
                }
            }
            //UpdateFieldContent($item_id, 'relation.......3', $field_content, false);
            // we do not use special field now - we just assign the SA and
            // Aktivity as normal (but form foreign plan)
            UpdateFieldContent($item_id, 'relation........', $field_content, false);
            ++$updated;
        }
    }
    echo "Upraveno $updated záznamù";
}
// new approach using standard AA widgets and form variables
elseif ($_POST['aaaction']=='DOCHANGE') {

    list($item_id, $field_id) = AA_Widget::parseId4Form($_POST['input_id']);
    $item   = AA_Item::getItem(new zids($item_id));
    $slice  = AA_Slices::getSlice($item->getSliceId());

    // Use right language (from slice settings) - languages are used for button texts, ...
    $lang    = $slice->getLang();
    $charset = $slice->getCharset();   // like 'windows-1250'
    bind_mgettext_domain(AA_INC_PATH."lang/".$lang."_output_lang.php3");

    $encoder       = new ConvertCharset;
    $field_content = AA_Value;
    // fill content array
    foreach ($_POST['content'] as $val) {
        $field_content->addValue($encoder->Convert($val, 'utf-8', $charset));
    }

    UpdateFieldContent($item_id, $field_id, $field_content);
    $changes = new AA_ChangesMonitor();
    $changes->deleteProposalForSelector($item_id, $field_id);

    $repre_value = GetRepreValue($item_id, $field_id, $_POST['alias_name']);
    echo $encoder->Convert($repre_value, $charset, 'utf-8');
}
elseif ($_POST['aaaction']=='DISPLAYINPUT') {

    $item        = AA_Item::getItem(new zids(AA_V::P('item_id')));
    $iid         = $item->getItemID();
    $slice       = AA_Slices::getSlice($item->getSliceId());

    // Use right language (from slice settings) - languages are used for button texts, ...
    $lang        = $slice->getLang();
    $charset     = $GLOBALS["LANGUAGE_CHARSETS"][$lang];   // like 'windows-1250'
    bind_mgettext_domain(AA_INC_PATH."lang/".$lang."_output_lang.php3");

    // we are posting only the alias name - otherwise the alias is expanded
    $alias       = (($_POST['alias_name'] == '') ? '' : '_#'.$_POST['alias_name']);
    $widget_html = $slice->getWidgetAjaxHtml($_POST['field_id'], $iid, $alias);

//    if ($iid == '9ef70aaad95b8abdd54f2f625c902346') {
//        setcookie("TestCookie", 'teal test', time()+3600);
//        $str = '{user},{user:id},{user:name},{user:password},{user:_#HEADLINE}';
//        echo AA_Stringexpand::unalias($str);
//    }

    $encoder = new ConvertCharset;
    echo $encoder->Convert($widget_html, $charset, 'utf-8');
}
elseif (AA_V::P('cancel_changes') AND AA_V::P('item_id') AND AA_V::P('field_id')) {
    $changes = new AA_ChangesMonitor();
    $changes->deleteProposalForSelector(AA_V::P('item_id'), AA_V::P('field_id'));
    $encoder = new ConvertCharset;
    $repre_value = GetRepreValue(AA_V::P('item_id'), AA_V::P('field_id'), AA_V::P('alias_name'));
    echo $encoder->Convert($repre_value, 'windows-1250', 'utf-8');
}
elseif (AA_V::P('do_change') AND AA_V::P('item_id') AND AA_V::P('field_id')) {
    $encoder       = new ConvertCharset;
    $val           = $encoder->Convert(AA_V::P('content'), 'utf-8', 'windows-1250');
    UpdateFieldContent(AA_V::P('item_id'), AA_V::P('field_id'), new AA_Value($val));
    $changes = new AA_ChangesMonitor();
    $changes->deleteProposalForSelector(AA_V::P('item_id'), AA_V::P('field_id'));
    $encoder = new ConvertCharset;
    $repre_value = GetRepreValue(AA_V::P('item_id'), AA_V::P('field_id'), AA_V::P('alias_name'));
    echo $encoder->Convert($repre_value, 'windows-1250', 'utf-8');
}
elseif (AA_V::P('item_id') AND AA_V::P('field_id')) {
    $encoder = new ConvertCharset;
    $text = $encoder->Convert(AA_V::P('content'), 'utf-8', 'windows-1250');

    $changes = new AA_ChangesMonitor();
    $changes->addProposal(new AA_ChangeProposal(AA_V::P('item_id'), AA_V::P('field_id'), array($text)));
    echo $encoder->Convert($text, 'windows-1250', 'utf-8');
}
elseif (AA_V::P('change_id')) {
    $changes = new AA_ChangesMonitor();

    // changes_arr[item_id] = [[field_id] => [0 => value1, 1 => value2, ...]]
    $changes_arr = $changes->getProposalByID(AA_V::P('change_id'));
    foreach ($changes_arr as $item_id => $fids) {
        if ( $item_id ) {
            foreach ( $fids as $fid => $values ) {
                if ( $fid ) {
                    // $values are normal array, but it is OK for the AA_Value constructor
                    UpdateFieldContent($item_id, $fid, new AA_Value($values));
                }
            }
        }
    }
    $changes->deleteProposalForSelector($item_id, $fid);

    $content4id    = new ItemContent($item_id);
    $encoder = new ConvertCharset;
    echo $encoder->Convert($content4id->getValue($fid), 'windows-1250', 'utf-8');
}


exit;
?>
