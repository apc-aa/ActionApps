<?php

/**
 * Item info - friendly overview of a single item (and, for superadmins, the full technical
 * detail). Reached from the item editor's left rail. Shares the editor's permission rule:
 * you may view an item's info if you may edit it.
 *
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/feeding.php";    // WhereFed/FromFed for the technical section
require_once __DIR__ . "/../include/itemfunc.php";
require_once __DIR__ . "/../include/menu_util.php";

try {
    $id = InputVars::idGETPOST('id');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

// Admin context: the viewer already passed the edit-rights gate below, so bypass the slice's
// reading password (otherwise the content - incl. posted_by used by that gate - reads as the
// "missing reading password" placeholder). renew=true overwrites any password-gated cache entry.
$item = $id ? AA_Items::getItem(new zids($id), null, true, true) : null;
if (!$item) {
    AA_Adminpageutil::MsgPageMenu(get_admin_url('index.php'), _m("Item not found."), 'admin');
    exit;
}

$sid = $item->getSliceID();
// Same gate as the editor (itemedit.php): edit-all in the slice, or you are the item's author.
if (!IfSlPerm(PS_EDIT_ALL_ITEMS, $sid) && $item->getval('posted_by.......') != AuthGetUid()) {
    AA_Adminpageutil::MsgPageMenu(get_admin_url('index.php'), _m("You have no rights to this item."), 'admin');
    exit;
}

$apage = new AA_Adminpageutil('itemmanager', 'info');   // 'itemmanager' keeps the top-bar highlight
$apage->setSubmenuKey('itemcontext_submenu');           // item-context left rail
$apage->setForm();   // no form wrapper around the page
$apage->setTitle(_m("Item info"));
$apage->printHead();

echo GetItemInfoHtml($item, $id, IsSuperadmin());

$apage->printFoot();
