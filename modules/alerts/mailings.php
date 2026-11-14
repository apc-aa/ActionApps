<?php

/**
 * Alerts admin: Mailings and statistics.
 *
 * The items the list of this module was sent, one row per item (its runs together), with what came
 * of them and - when the list has a statistics slice - how many opened and clicked; a chosen mailing
 * shows its runs and its links below. Only reads: the Editor role of the module sees this page alone.
 *
 * @package Alerts
 */
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

use AA\Mailbulk\Campaign;
use AA\Mailbulk\Overview;
use AA\Mailbulk\Stats;
use AA\Mailbulk\StatsView;
use AA\Util\InputVars;

require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/alerts_sending.php";

// the type before the right: in another module type the letter of this right means something else
if (!AA_Module_Alerts::getOwnModule(AA::$module_id)) {
    MsgPage(_m("Module not found."));
}
if (!IfSlPerm(PS_ALERTS_STATS)) {
    MsgPage(_m("You do not have permissions to access this page"));
}
$overview = Overview::forModule((string) AA::$module_id);
if ($overview === null) {
    MsgPage(_m("This Alerts module has no list of readers."));
}

try {
    $page_no = InputVars::intGET('page');
    $mailing = InputVars::idGET('mailing');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

const MAILINGS_PER_PAGE = 50;

$mailingsUrl = static fn(array $params): string => get_url(AA_INSTAL_PATH . 'modules/alerts/mailings.php', ['module_id' => AA::$module_id] + $params);

$list      = new AA_Collection($overview->list());
$statSlice = $list->getStatSliceId();
$pages     = max(1, (int) ceil($overview->count() / MAILINGS_PER_PAGE));
$page_no   = min(max(1, $page_no), $pages);

$apage = new AA_Adminpageutil('admin', 'mailings');
$apage->setTitle(_m("Mailings and statistics"));
$apage->printHead();

if ($statSlice === '') {
    $text = safe(_m("Opens and clicks are not measured: the list has no statistics slice."));
    if (IfSlPerm(PS_USERS)) {
        $text .= ' <a href="' . safe(StateUrl(AA_INSTAL_PATH . 'modules/alerts/modedit.php')) . '">' . safe(_m("Settings")) . '</a>';
    }
    echo "<p>$text</p>";
}

$head = [_m("Date"), _m("Name"), _m("State"), _m("Recipients"), _m("Sent"), _m("Failed"), _m("Uncertain")];
if ($statSlice !== '') {
    array_push($head, _m("Opened"), _m("Clicked"));
}
$table = [array_map(safe(...), $head)];
$stats = new Stats();
// the figures only of the rows shown: each reads the runs of its sending
foreach ($overview->page($page_no, MAILINGS_PER_PAGE) as $row) {
    $counts = Campaign::of($list->getId(), $row['item'])->counts();
    $title  = ($row['title'] !== '') ? $row['title'] : $row['item'];
    $link   = $mailingsUrl(['page' => $page_no, 'mailing' => $row['item']]) . '#aa-mailing';
    $cells  = [
        safe(($row['date'] > 0) ? date('Y-m-d H:i', $row['date']) : ''),
        '<a href="' . safe($link) . '">' . safe($title) . '</a>',
        safe(StatsView::state($row['state'])),
        $counts['total'],
        $counts['sent'],
        $counts['failed'],
        $counts['uncertain'],
    ];
    if ($statSlice !== '') {
        $opens = $stats->counts($statSlice, $row['item']);
        array_push($cells, $opens['opened'], $opens['clicked']);
    }
    $table[] = $cells;
}

if (count($table) > 1) {
    echo GetHtmlTable($table, 'th');
} else {
    echo '<p>' . safe(_m("The list has sent nothing yet.")) . '</p>';
}

if ($pages > 1) {
    $nav = [];
    if ($page_no > 1) {
        $nav[] = '<a href="' . safe($mailingsUrl(['page' => $page_no - 1])) . '">' . safe(_m("Newer")) . '</a>';
    }
    $nav[] = safe(_m("Page %1 of %2", [$page_no, $pages]));
    if ($page_no < $pages) {
        $nav[] = '<a href="' . safe($mailingsUrl(['page' => $page_no + 1])) . '">' . safe(_m("Older")) . '</a>';
    }
    echo '<p>' . implode(' | ', $nav) . '</p>';
}

// an item id from the request shows only a mailing of this list
if (($mailing !== '') && $overview->has($mailing)) {
    echo '<div id="aa-mailing">' . (new StatsView())->mailing($list, $mailing) . '</div>';
}

$apage->printFoot();
