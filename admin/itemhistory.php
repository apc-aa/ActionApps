<?php
/**
 * Item history - the recorded changes (when / who) for a single item, with per-field diffs and a
 * revert tool. Visible to anyone who may edit the item (edit-all on the slice, or the item's
 * author) - the same gate as the editor. Reached from the item editor's left rail.
 *
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

use AA\Util\ChangesMonitor;
use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/itemfunc.php";
require_once __DIR__ . "/../include/menu_util.php";

try {
    $id = InputVars::idGETPOST('id');
} catch (Exception $e) { AA_Adminpageutil::pageParameterError($e); }

// Admin context: bypass the slice reading password so a password-protected item still resolves its
// name and history (see iteminfo.php for the rationale). renew=true overwrites any gated cache entry.
$item = $id ? AA_Items::getItem(new zids($id), null, true, true) : null;
if (!$item) {
    AA_Adminpageutil::MsgPageMenu(get_admin_url('index.php'), _m("Item not found."), 'admin');
    exit;
}

// Same gate as the editor (itemedit.php / iteminfo.php): edit-all in the slice, or you are the
// item's author. The "Compare to" AJAX swap is served by AA_Responder_Item_History_Diff, which
// re-checks this same permission independently.
if (!IfSlPerm(PS_EDIT_ALL_ITEMS, $item->getSliceID()) && $item->getval('posted_by.......') != AuthGetUid()) {
    AA_Adminpageutil::MsgPageMenu(get_admin_url('index.php'), _m("You have no rights to this item."), 'admin');
    exit;
}

$apage = new AA_Adminpageutil('itemmanager', 'history');
$apage->setSubmenuKey('itemcontext_submenu');
$apage->setForm();
$apage->setTitle(_m("History"));
$apage->printHead();

// Full change history with per-field diffs and restore - the same renderer the AA finder uses
// (ChangesMonitor::display), so both pages improve together.
ChangesMonitor::singleton()->display([$id]);

$apage->printFoot();
