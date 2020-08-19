<?php
/**
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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/
//
// Message page with menu. Can't be in util.php3 since of the menu usage.
//

require_once __DIR__."/constants.php3";

/** MsgPageMenu function
 *  Displays page with message and link to $url
 * @param $url - where to go if user clicks on Back link on this message page
 * @param $msg - displayed message
 * @param $mode - items/admin/standalone for surrounding of message
 * @param $menu
 */
function MsgPageMenu($url, $msg, $mode, $menu="") {
    global $sess;

    if ( !isset($sess) ) {
        require_once __DIR__."/locauth.php3";
        pageOpen();
    }

    $apage = new AA_Adminpageutil($mode=='admin'? 'aaadmin' : 'sliceadmin', $menu);
    $apage->setForm();
    $apage->printHead($err, $msg);

    echo "<a href=\"$url\">"._m("Back")."</a>";
    $apage->printFoot();

    exit;
}


