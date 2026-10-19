<?php

/**
 * Pairs the items of the slice with the public register of data boxes and writes the boxes a person
 * ticks into the items; the settings of the pairing (AA\Isds\Registry\Page).
 *
 * PHP version 8.2+
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999-2026 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

use AA\Isds\Registry\Page;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";

$post = [];
try {
    $status = Page::statusOf(InputVars::stringGET('status'));
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $post = [
            'action' => InputVars::stringPOST('action'),
            'token'  => InputVars::stringPOST('token'),
            'status' => InputVars::stringPOST('status'),
            'stamp'  => InputVars::stringPOST('stamp'),
            'tick'   => InputVars::arrayPOST('tick'),
            'aa'     => InputVars::arrayPOST('aa'),
        ];
    }
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

// the report reads the items past the slice's reading password and the write changes them - both need
// PS_EDIT_ALL_ITEMS (Page checks it); setting the pairing up needs PS_FIELDS
if (!(AA_Slice::getModule(AA::$module_id) instanceof AA_Slice) || !(IfSlPerm(PS_EDIT_ALL_ITEMS) || IfSlPerm(PS_FIELDS))) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permissions to pair the items of this slice with the register of data boxes"), "admin");
}

$page = Page::forRequest((string) AA::$module_id);

// an action is answered by a redirect to this page, so a reload never repeats it
$outcome = $page->handle($post, $_SERVER);
if ($outcome !== null) {
    go_url($page->afterPost($outcome, $post['status']), '', false, 303);
}

$apage = new AA_Adminpageutil('sliceadmin', 'isds_registry');
$apage->setTitle(_m('Data boxes from the register'));
// each action has its own form
$apage->setForm();
$apage->addRequire(Page::CSS, 'AA_Req_CSScode');
// before the head: the widgets of the settings form add the scripts they need
$body = $page->html($status);
$apage->printHead();

echo $body;

$apage->printFoot();
