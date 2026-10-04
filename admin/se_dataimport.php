<?php

/**
 * The detail of one import of the slice: overview, actions, runs (AA\Import\DetailPage).
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\Import\Definition;
use AA\Import\DetailPage;
use AA\Import\Run;

require_once __DIR__ . "/../include/init_page.php";

// imports fill slices only - a site or alerts admin holds the feeding right too
if (!Definition::mayImportInto(AuthGetUid(), (string) AA::$module_id)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permissions to manage the imports of this slice"), "admin");
    exit;
}

// an import of another slice gets the same answer as one that does not exist
$page = DetailPage::forRequest((string) AA::$module_id, $_GET['id'] ?? null, AuthGetUid());
if ($page === null) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "se_dataimports.php", _m("There is no such import in this slice."), "admin");
    exit;
}

// an action is answered by a redirect to this page, so a reload never repeats it
$outcome = $page->handle($_POST, $_SERVER);
if ($outcome !== null) {
    go_url($page->afterPost($outcome), '', false, 303);
}

$apage = new AA_Adminpageutil('sliceadmin', 'dataimports');
$apage->setTitle(_m('Import: %1', [safe((string) $page->definition()->getName())]));
// each action has its own form
$apage->setForm();
$apage->addRequire('
    .aa-import-facts th { text-align: left; padding-right: 1em; vertical-align: top; }
    .aa-import-facts, .aa-import-runs { font-variant-numeric: tabular-nums; }
    .aa-import-runs { width: 100%; border-collapse: collapse; }
    .aa-import-runs th { text-align: left; }
    .aa-import-runs th, .aa-import-runs td { padding: 0.3em 0.6em; vertical-align: top; }
    .aa-import-actions { margin: 0.5em 0; }
    .aa-import-why { margin: 0.3em 0; }
    .aa-import-sample { margin: 0.3em 0; }
', 'AA_Req_CSScode');
// the page marks a submitted form as updating; a cancelled one is not
$apage->addRequire("document.querySelectorAll('form[data-aa-confirm]').forEach((f) => f.addEventListener('submit', (e) => { if (!confirm(f.dataset.aaConfirm)) { e.preventDefault(); f.classList.remove('aa-updating'); } }));", 'AA_Req_Load');
$apage->printHead();

echo $page->outcomeOnceHtml();
echo $page->html(Run::clock());

$apage->printFoot();
