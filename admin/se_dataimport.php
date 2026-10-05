<?php

/**
 * The detail of one import of the slice: overview, actions, runs, settings of the source and
 * mapping (AA\Import\DetailPage).
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
use AA\Import\DetailLive;
use AA\Import\DetailPage;
use AA\Import\Run;

// The script of the page asks every 5 s while a run goes on, maybe all night (AA\Import\DetailLive):
// JSON or the live sections, never the login form. It stores nothing - neither the session nor a
// request log row; only a session left in the middle of a login (uid 'form') logs one LOGIN row, as
// every AA page does then.
if ((isset($_GET['progress']) || isset($_GET['sections'])) && (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET')) {
    // stray output of the bootstrap would turn the answer into HTML: the answer is built inside the buffer
    ob_start();
    require_once __DIR__ . "/../include/config.php";
    require_once __DIR__ . "/../include/mgettext.php";
    require_once __DIR__ . "/../include/locauth.php";
    require_once __DIR__ . "/../include/util.php";
    require_once __DIR__ . "/../include/event.class.php";
    require_once __DIR__ . "/../include/slice.class.php";
    $answer = DetailLive::answer($_GET);
    ob_end_clean();
    DetailLive::emit($answer);
    exit;
}

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
    .aa-import-mapping, .aa-import-keys { border-collapse: collapse; }
    .aa-import-mapping th, .aa-import-keys th { text-align: left; }
    .aa-import-mapping th, .aa-import-mapping td, .aa-import-keys th, .aa-import-keys td { padding: 0.3em 0.6em; vertical-align: top; }
    .aa-import-mapping code { white-space: pre-wrap; overflow-wrap: anywhere; }
    .aa-import-settings, .aa-import-source { margin: 0.5em 0; }
', 'AA_Req_CSScode');
// asks before End and before a change of the settings, follows a running run
$apage->addRequire(get_aa_url('javascript/dataimport.min.js', ['v' => AA_JS_VERSION], false));
// before the head: the widgets of the settings form add the scripts they need
$body = $page->html(Run::clock()) . $page->config()->html($_GET['source'] ?? null);
$apage->printHead();

echo $page->outcomeOnceHtml();
echo $body;

$apage->printFoot();
