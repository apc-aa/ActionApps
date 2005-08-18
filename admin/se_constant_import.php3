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

// Parameters: group_id - identifier of constant group
//             categ - if true, constants are taken as category, so
//                     APC parent categories are displayed for selecting parent
//             category - edit categories for this slice (no group_id nor categ required)
//             as_new - if we want to create new category group based on an existing (id of "template" group)

require_once "../include/init_page.php3";
require_once $GLOBALS['AA_INC_PATH']."formutil.php3";
require_once $GLOBALS['AA_INC_PATH']."varset.php3";
require_once $GLOBALS['AA_INC_PATH']."pagecache.php3";
require_once $GLOBALS['AA_INC_PATH']."constedit_util.php3";
require_once $GLOBALS['AA_INC_PATH']."msgpage.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FIELDS)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

$back_url = ($return_url ? ($fid ? con_url($return_url,"fid=".$fid) : $return_url) : "index.php3");

if ($update) {
    do {
        $err["Init"] = "";          // error array (Init - just for initializing variable
        $new_group_id = stripslashes(str_replace(':','-',$new_group_id));  // we don't need ':'
                                                             // in id (parameter separator)
        ValidateInput("new_group_id", _m("Constant Group"), $new_group_id, $err, true, "text");
        ValidateInput("constant_list", _m("Constants"), $constant_list, $err, true, "text");
        if (count($err) > 1) {
            break;
        }

        $constants = explode("\n", stripslashes($constant_list));  // stripslashes - the constants is unfortunately magic_quoted
        if (count($constants) < 1) {
            $err[] = _m('No constants specified');
            break;
        }
        $constants2import = array();
        foreach ($constants as $constant) {
            if ($delimiter) {
                list($name,$value) = explode($delimiter, $constant);
            } else {
                $name = $value = $constant;
            }
            $constants2import[] = array('name' => $name, 'value' => $value);
        }

        $ok = add_constant_group($new_group_id, $constants2import);

        if ($ok !== true) {
            $err[] = $ok;
            break;
        }

        if (count($err) <= 1) {
            $Msg .= MsgOK(_m("Constants update successful"));
        }
        go_url($sess->url(get_url($back_url, 'Msg='.urlencode($Msg))));
    } while( 0 );           // in order we can use "break;" statement
}


HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Admin - Constants Import");?></TITLE>
</HEAD>
<?php

require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
showMenu($aamenus, "sliceadmin", "");

echo "<H1><B>" . _m("Admin - Constants Import") . "</B></H1>";
PrintArray($err);
echo $Msg;

$form_buttons = array("update",
                      "cancel"=>array("url"=> $back_url),
                      "return_url"=>array("value"=>$return_url),
                      "fid"=>array("value"=>$fid));
?>
<form method=post name="f" action="<?php echo $sess->url($PHP_SELF) ?>">
<?php
FrmTabCaption(_m("Constants"), '', '', $form_buttons, $sess, $slice_id);
FrmInputText('new_group_id', _m("Constant Group"), $new_group_id);
$delimiters = array(''   => '-none- (Name is the same as Value)',
                    ';'  => 'semicolon ;',
                    ','  => 'comma ,',
                    '\t' => 'tabulator \t',
                    '|'  => 'pipe |',
                    '~'  => 'tilde ~');
FrmInputSelect('delimiter', _m('Name - Value delimiter'), $delimiters, '', true);
FrmTextarea('constant_list', _m("Constants"), $constant_list, 25, 60, false, _m('write each constant to new row in form <name><delimiter><value> (or just <name> if the values should be the same as names)'));
FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</form>
<?php
HtmlPageEnd();
page_close()?>
