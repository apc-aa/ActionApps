<?php

/**
 * Item statistics - total views and a per-day hit table for a single item, with a link to the
 * full statistics dashboard. Superadmin only. Reached from the item editor's left rail.
 *
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

use AA\Util\InputVars;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/itemfunc.php";
require_once __DIR__ . "/../include/menu_util.php";

try {
    $id = InputVars::idGETPOST('id');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

if (!IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(get_admin_url('index.php'), _m("You don't have permissions to view item statistics."), 'admin');
    exit;
}

// Admin context: bypass the slice reading password so a password-protected item still shows its
// stats and name (see iteminfo.php for the rationale). renew=true overwrites any gated cache entry.
$item = $id ? AA_Items::getItem(new zids($id), null, true, true) : null;
if (!$item) {
    AA_Adminpageutil::MsgPageMenu(get_admin_url('index.php'), _m("Item not found."), 'admin');
    exit;
}

$apage = new AA_Adminpageutil('itemmanager', 'stats');
$apage->setSubmenuKey('itemcontext_submenu');
$apage->setForm();
$apage->setTitle(_m("Statistics"));
$apage->addRequire('chartjs@4');   // load the charting lib before <head> is emitted (renderTable draws charts)
$apage->printHead();

echo GetItemStatsHtml($item, $id);

$apage->printFoot();
