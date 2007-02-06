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

error_reporting(E_ERROR | E_PARSE);

class AA_V {
    function P($var=null) {
        AA_V::_unquote();
        return isset($var) ? $_POST[$var] : $_POST;
    }

    function G($var=null) {
        AA_V::_unquote();
        return isset($var) ? $_GET[$var] : $_GET;
    }

    function C($var=null) {
        AA_V::_unquote();
        return isset($var) ? $_COOKIE[$var] : $_COOKIE;
    }

    function _unquote() {
        if (!$GLOBALS['magic_unquoted']) {
           if ( get_magic_quotes_gpc() ) {
               $_POST   = AA_V::stripslashes_deep($_POST);
               $_GET    = AA_V::stripslashes_deep($_GET);
               $_COOKIE = AA_V::stripslashes_deep($_COOKIE);
            }
            $GLOBALS['magic_unquoted'] = true;
        }
    }

    function stripslashes_deep($value) {
        return is_array($value) ? array_map(array('AA_V','stripslashes_deep'), $value) : stripslashes($value);
    }

}



/** field_content is in normal multivalue array ([][value]=...) */
function UpdateFieldContent($item_id, $field_id, $field_content, $invalidate = true) {
    $changes       = new AA_ChangesMonitor();
    $changes->addHistory(new AA_ChangeProposal($item_id, $field_id, array(GetRepreValue($item_id, $field_id))));

    $content4id    = new ItemContent($item_id);
    $sli_id        = $content4id->getSliceID();
    unset($content4id);

    $newcontent4id = new ItemContent();
    $newcontent4id->setFieldValue($field_id, $field_content);

    $newcontent4id->setItemID($item_id);
    $newcontent4id->setSliceID($sli_id);
    $updated_items = 0;
    if ($newcontent4id->storeItem( 'update', $invalidate, false)) {    // invalidatecache, not feed
        $updated_items++;
    }
}

function GetRepreValue($item_id, $field_id, $alias_name) {
    $item        = GetItemFromId(new zids($item_id));
    $repre_value = $alias_name ? $item->subst_alias('_#'.$alias_name) : $item->getval($field_id);
    return get_if($repre_value, '--');
}

// BSC assignment
if ( AA_V::P('bsc') == 1 ) {
    $updated = 0;
    foreach (AA_V::P() as $varname => $varvalue) {
        if (strlen($varname)>20 AND substr($varname,0,3)=='bsc') {
            $item_id = substr($varname,3);
            $field_content = array();
            foreach ( explode(',', $varvalue) as $related_id ) {
                if ( $related_id ) {
                    $field_content[] = array('value'=>$related_id, 'flag'=>0);
                }
            }
            UpdateFieldContent($item_id, 'relation.......3', $field_content, false);
            ++$updated;
        }
    }
    echo "Upraveno $updated záznamù";
}
elseif (AA_V::P('form') AND AA_V::P('item_id') AND AA_V::P('field_id')) {
    $item        = GetItemFromId(new zids(AA_V::P('item_id')));
    $iid         = $item->getItemID();
    $field_id    = AA_V::P('field_id');
    $fid         = unpack_id($field_id);
    $combi_id    = $iid. '_'. $fid;
//    $widget_html = $item->getWidgetHtml($fid);
    $value       = safe($item->getval(AA_V::P('field_id')));
    $repre_value = safe(GetRepreValue(AA_V::P('item_id'), AA_V::P('field_id'), AA_V::P('alias_name')));
    $ret         = "<input type=\"text\" size=\"80\" id=\"ajaxi_$combi_id\" value=\"$value\">";
    $ret        .= "<input type=\"button\" value=\"ULOŽIT ZMÌNU\" onclick=\"proposeChange('$combi_id', '$iid', '$field_id', (typeof do_change == 'undefined') ? 1 : do_change)\">";
    $ret        .= "<input type=\"button\" value=\"storno\" onclick=\"SetContent('ajaxv_$combi_id', '$repre_value'); document.getElementById('ajaxv_$combi_id').setAttribute('aaedit', '0');\">";
    $ret        .= " <input type=\"hidden\" id=\"ajaxh_$combi_id\" value=\"$repre_value\">";
    $encoder = new ConvertCharset;
    echo $encoder->Convert($ret, 'windows-1250', 'utf-8');
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
    $field_content = array(array('value'=>$val, 'flag'=>0));
    UpdateFieldContent(AA_V::P('item_id'), AA_V::P('field_id'), $field_content);
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
                    $field_content = array();
                    foreach ( $values as $val ) {
                        $field_content[] = array('value'=>$val, 'flag'=>0);
                    }
                    UpdateFieldContent($item_id, $fid, $field_content);
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
