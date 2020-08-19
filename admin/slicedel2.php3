<?php
/**
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
// expected $del - unpacked id of slice to delete

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/feeding.php3";
require_once __DIR__."/../include/msgpage.php3";
require_once __DIR__."/../include/modutils.php3";


if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

if ($del OR $deletearr) {
    if (!IsSuperadmin()) {
        MsgPage(StateUrl(self_base())."index.php3", _m("You don't have permissions to delete slice."));
        exit;
    }
} else {
    MsgPage(StateUrl(self_base())."index.php3", _m("You don't have permissions to delete slice."));
    exit;
}

$err = [];      // error array (Init - just for initializing variable
//AA::$debug = 127;

if (!AA_Module::deleteModules($del ? [$del] : $deletearr)) {
    go_url(get_admin_url("slicedel.php3?Msg=". urlencode(_m("No such module."))));
}
//AA::$dbg->duration_stat();
//exit;

page_close();                                // to save session variables

// There is a bug in here, that typically if you go SliceAdmin->delete->AA->delete it
// will delete your current slice, and leave you nowhere to go to, you have to login again (mitra)
go_url(get_admin_url("slicedel.php3?Msg=".rawurlencode(_m("Slice successfully deleted, tables are optimized"))));


