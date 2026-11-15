<?php

/**
 * Alerts admin: Mailings and statistics.
 *
 * The items the list of this module was sent, one row per item (its runs together), with what came
 * of them and - when the list has a statistics slice - how many opened and clicked; a chosen mailing
 * shows its runs, its links and its recipients below (also as CSV). Only reads: the Editor role of the
 * module sees this page alone, the recipients with their addresses included.
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

use AA\Mailbulk\Outcome;
use AA\Mailbulk\Overview;
use AA\Mailbulk\RecipientReport;
use AA\Mailbulk\Stats;
use AA\Mailbulk\StatsView;
use AA\Util\InputVars;

require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/scroller.php";
require_once __DIR__ . "/alerts_sending.php";

// no module: the start after login - the index of this module would send an Editor back here
$start = get_admin_url('index.php', ['module_id' => ''], false);
// the type before the right: in another module type the letter of this right means something else
if (!AA_Module_Alerts::getOwnModule(AA::$module_id)) {
    MsgPage(_m("Module not found."), $start);
}
if (!IfSlPerm(PS_ALERTS_STATS)) {
    MsgPage(_m("You do not have permissions to access this page"), $start);
}
$collection = GetCollection(AA::$module_id);
if (!is_array($collection)) {
    MsgPage(_m("This Alerts module has no list of readers."), $start);
}

try {
    $mailing = InputVars::idGET('mailing');
    $asked   = InputVars::stringGET('rcpt');
    $csv     = InputVars::intGET('csv') === 1;
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

const MAILINGS_PER_PAGE = 50;

$list      = new AA_Collection((string) $collection['id']);
$overview  = new Overview($list->getId());
$statSlice = $list->getStatSliceId();
$scroller  = new AA_Scroller_Easy('mb', MAILINGS_PER_PAGE, $overview->count(), get_url(AA_INSTAL_PATH . 'modules/alerts/mailings.php', [], true));
$scroller->update();
$page_no   = $scroller->getCurrent();

// an item id from the request shows only a mailing of this list
$report = (($mailing !== '') && $overview->has($mailing)) ? new RecipientReport($list, $overview->campaign($mailing)) : null;
$filter = $report?->filter($asked) ?? RecipientReport::ALL;
if ($report && $csv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mailing-' . strtolower($mailing) . "-$filter.csv\"");
    $report->csv($filter, fopen('php://output', 'w'));
    exit;
}

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

$head = [_m("Date"), _m("Name"), _m("State"), _m("Recipients"), StatsView::outcome(Outcome::Sent->value), StatsView::outcome(Outcome::Failed->value), StatsView::outcome(Outcome::Uncertain->value)];
if ($statSlice !== '') {
    array_push($head, _m("Opened"), _m("Clicked"));
}
$table = [array_map(safe(...), $head)];
$rows  = $overview->page($page_no, MAILINGS_PER_PAGE);
$opens = ($statSlice !== '') ? (new Stats())->countsFor($statSlice, $list->getId(), array_column($rows, 'item')) : [];
foreach ($rows as $row) {
    $link  = get_url(AA_INSTAL_PATH . 'modules/alerts/mailings.php', ['scr_mb_Go' => $page_no, 'mailing' => $row['item']], true) . '#aa-mailing';
    $cells = [
        safe(StatsView::date($row['date'])),
        '<a href="' . safe($link) . '">' . safe($row['title']) . '</a>',
        safe(StatsView::state($row['state'])),
        $row['counts']['total'],
        $row['counts']['sent'],
        $row['counts']['failed'],
        $row['counts']['uncertain'],
    ];
    if ($statSlice !== '') {
        array_push($cells, $opens[$row['item']]['opened'], $opens[$row['item']]['clicked']);
    }
    $table[] = $cells;
}

if (count($table) > 1) {
    echo GetHtmlTable($table, 'th');
} else {
    echo '<p>' . safe(_m("The list has sent nothing yet.")) . '</p>';
}
echo $scroller->getNavbar();

if ($report) {
    echo '<div id="aa-mailing">' . (new StatsView())->mailing($list, $mailing, $overview->campaign($mailing)) . '</div>';

    // the table pages within the chosen mailing and filter
    $url       = get_url(AA_INSTAL_PATH . 'modules/alerts/mailings.php', ['scr_mb_Go' => $page_no, 'mailing' => $mailing], true);
    $rows      = $report->rows($filter);
    $rscroller = new AA_Scroller_Easy('rc', RecipientReport::PER_PAGE, count($rows), get_url($url, ['rcpt' => $filter]) . RecipientReport::ANCHOR);
    $rscroller->update();
    echo '<h3 id="aa-recipients">' . safe(_m('Recipients')) . '</h3>' . $report->filterBar($filter, $url);
    echo $rows ? $report->table($rows, $rscroller->getCurrent()) . $rscroller->getNavbar() : '<p>' . safe(_m('No recipient matches the filter.')) . '</p>';
    echo $report->note();
}

$apage->printFoot();
