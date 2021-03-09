<?php
/**
 * Parameters: group_id - identifier of constant group
 *             categ - if true, constants are taken as category, so
 *                     APC parent categories are displayed for selecting parent
 *             category - edit categories for this slice (no group_id nor categ required)
 *             as_new - if we want to create new category group based on an existing (id of "template" group)
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

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/constedit_util.php3";
require_once __DIR__."/../include/msgpage.php3";

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FIELDS)) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

$back_url = ($return_url ? ($fid ? con_url($return_url,"fid=".$fid) : $return_url) : "index.php3");

if ($update) {
    do {
        $err = [];          // error array (Init - just for initializing variable
        $new_group_id = stripslashes(str_replace(':','-',$new_group_id));  // we don't need ':'
                                                             // in id (parameter separator)
        ValidateInput("new_group_id", _m("Constant Group"), $new_group_id, $err, true, "text");
        ValidateInput("constant_list", _m("Constants"), $constant_list, $err, true, "text");
        if (count($err)) {
            break;
        }

        $constants = explode("\n", stripslashes($constant_list));  // stripslashes - the constants is unfortunately magic_quoted
        if (count($constants) < 1) {
            $err[] = _m('No constants specified');
            break;
        }
        $constants2import = [];
        foreach ($constants as $constant) {
            if ($delimiter) {
                [$name,$value] = explode($delimiter, $constant);
            } else {
                $name = $value = $constant;
            }
            $constants2import[] = ['name' => $name, 'value' => $value];
        }

        $ok = add_constant_group($new_group_id, $constants2import);

        if ($ok !== true) {
            $err[] = $ok;
            break;
        }

        if (!count($err)) {
            $Msg .= MsgOk(_m("Constants update successful"));
        }
        go_url(StateUrl(get_url($back_url, 'Msg='.urlencode($Msg))));
    } while( 0 );           // in order we can use "break;" statement
}


$apage = new AA_Adminpageutil('sliceadmin','fields');
$apage->setTitle(_m("Admin - Constants Import"));
$apage->printHead($err, $Msg);

$form_buttons = [
    "update",
                      "cancel"    => ["url"=> $back_url],
                      "return_url"=> ["value"=>$return_url],
                      "fid"       => ["value"=>$fid]
];

FrmTabCaption(_m("Constants"), $form_buttons);
FrmInputText('new_group_id', _m("Constant Group"), $new_group_id, 16, 16);
$delimiters = [
    ''   => '-none- (Name is the same as Value)',
                    ';'  => 'semicolon ;',
                    ','  => 'comma ,',
                    '\t' => 'tabulator \t',
                    '|'  => 'pipe |',
                    '~'  => 'tilde ~'
];
FrmInputSelect('delimiter', _m('Name - Value delimiter'), $delimiters, '', true);
FrmTextarea('constant_list', _m("Constants"), $constant_list, 25, 60, false, _m('write each constant to new row in form <name><delimiter><value> (or just <name> if the values should be the same as names)'));
FrmTabEnd($form_buttons);

$apage->printFoot();
