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
 * @author    Honza Malík, August 2023
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\Later\HitcounterStats;
use AA\Util\InputVars;
use AA\Widget\Widget;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/mgettext.php";
// ----------------------------------------------------------------------------------------
try {
    $items_code = InputVars::stringGETPOST('items_code');  // could be posted as url parameter as well
    $item_count = InputVars::intPOST('item_count');
    $item_set   = InputVars::stringPOST('item_set');
    $date_from  = InputVars::stringPOST('date_from');
} catch (Exception) {}

if (!IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You have not permissions to see statistics"), "admin");
    exit;
}

$item_count = $item_count ?: 5;

//$script2run = '
//   var opts = { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" };
//   window.cm_items  = CodeMirror.fromTextArea(document.getElementById("items"), opts);
//   ';
$apage = new AA_Adminpageutil('sliceadmin','statistics');
$apage->setTitle(_m("Admin - Statistics"));
//$apage->addRequire($script2run, 'AA_Req_Load');
$apage->addRequire('chartjs@4');
$apage->printHead();

$form_buttons = ["show" => ["type"=>"submit", "value"=>_m("Show")]];

define('HITCOUNTER_OPTIONS', [
    'years'    => _m('years'),
    'months'   => _m('months'),
    'weeks'    => _m('weeks'),
    'days'     => _m('days'),
    'hours'    => _m('hours'),
    'minutes'  => _m('minutes'),
    'dayhours' => _m('day-hours'),
    'weekdays' => _m('week-days'),
]);

define ('ITEM_SETS', [
    'exact'      => _m('Specified below'),
    'all'        => _m('Top all time'),
    'year'       => _m('Top this year'),
    'month'      => _m('Top this month'),
    'week'       => _m('Top this week'),
    'day'        => _m('Top this day'),
    'last_year'  => _m('Top last year'),
    'last_month' => _m('Top last month'),
    'last_week'  => _m('Top last week'),
    'last_day'   => _m('Top last day'),
    ]);

// ------------------------------------------------------------------------------------------
FrmTabCaption(_m('Item Statistics'));
FrmInputSelect('item_set', _m('Show items'), ITEM_SETS, $item_set, true);
FrmTextarea('items_code', _m('Exact items to show'), $items_code, 4, 60, false, _m('dash-separated list of item ids/short_ids or AA expression like {ids:{_#SLICE_ID}:d-publish_date....-gt-{timestamp:2023-08-22}}}'));
FrmInputNumber('item_count', _m('Number of items to show'), $item_count, 1, 9999, true);
FrmInputDate('date_from', _m('From date'), $date_from, );

//        //$widget_definition_string = 'dte::::2';
//        $widget_definition_string = 'mch:::::::::{"1"#:"Yes","0"#:"No"}:';
//        $params       = Widget::parseClassProperties($widget_definition_string);
//        $widget_class = $params['class'];

$w = Widget::factoryByName('mch', ['const_arr' => HITCOUNTER_OPTIONS]);
 //echo $w->getHtml(new AA_Property( 'stats', _m('Items to show'), 'string', true), (new AA_Content())->setId('admin_statistics')->setValue('stats','1324567899'));

if ($_POST) {
    $grabber = new AA\IO\Grabber\ObjectForm();
    $grabber->prepare();
    $content = $grabber->getItem();
    $grabber->finish();
    // huhl($grabber->getAllItems());
} else {
    $content = (new AA_Content())->setId('admin_statistics');
}

echo $w->getHtml(new AA_Property( 'stats', _m('Stats to show'), 'string', true), $content);

FrmTabEnd($form_buttons);


echo '<br><br><div class=aa-tab-title>'._m('Display Statistics for Item').'</div>';

huhl($date_from);

// determine, which items to show
$items = match ($item_set) {
    'all'        => implode('-', array_keys(HitcounterStats::getStats(AA::$module_id, 0, 0, true, $item_count))),
    'year'       => implode('-', array_keys(HitcounterStats::getStats(AA::$module_id, time() - 60 * 60 * 24 * 365, 0, true, $item_count))),
    'month'      => implode('-', array_keys(HitcounterStats::getStats(AA::$module_id, time() - 60 * 60 * 24 * 30, 0, true, $item_count))),
    'week'       => implode('-', array_keys(HitcounterStats::getStats(AA::$module_id, time() - 60 * 60 * 24 * 7, 0, true, $item_count))),
    'day'        => implode('-', array_keys(HitcounterStats::getStats(AA::$module_id, time() - 60 * 60 * 24, 0, true, $item_count))),
    'last_year'  => implode('-', array_keys(HitcounterStats::getStats(AA::$module_id, time() - 60 * 60 * 24 * 365 * 2, time() - 60 * 60 * 24 * 365, true, $item_count))),
    'last_month' => implode('-', array_keys(HitcounterStats::getStats(AA::$module_id, time() - 60 * 60 * 24 * 30 * 2, time() - 60 * 60 * 24 * 30, true, $item_count))),
    'last_week'  => implode('-', array_keys(HitcounterStats::getStats(AA::$module_id, time() - 60 * 60 * 24 * 7 * 2, time() - 60 * 60 * 24 * 7, true, $item_count))),
    'last_day'   => implode('-', array_keys(HitcounterStats::getStats(AA::$module_id, time() - 60 * 60 * 24 * 2, time() - 60 * 60 * 24, true, $item_count))),
    'exact'      => StrExp('ids', [AA::$module_id, '', '', '-', AA::Un($items_code) ?: 'xxx']),
    default      => '',
};

if (strlen($items)) {
    $template = '<div class=aa-table>';
    foreach ($content->getValuesArray('stats') as $code) {
        $name = HITCOUNTER_OPTIONS[$code];
        $from = $date_from ? strtotime($date_from) : 0;
        $template .= "<h2>$name</h2>\n{hitcounter:$code:$items:$from}\n";
    }
    $template .= '</div>';
    $template .= '<div>'._m('Items found').': '. StrExp('count', [$items]).'</div>';
    //FrmStaticText(_m('Display Statistics for Item'), AA::Un($template), '', '', false);
    echo AA::Un($template);
} else {
    echo MsgOk(_m('No items found'));
}

echo getFrmTabEnd($form_buttons);

$apage->printFoot();
