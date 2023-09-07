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

use AA\Util\InputVars;
use AA\Widget\Widget;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/mgettext.php";
// ----------------------------------------------------------------------------------------
try {
    $items = InputVars::stringGETPOST('items');  // could be posted as url parameter as well
} catch (Exception $e) {}

if (!IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php3", _m("You have not permissions to see statistics"), "admin");
    exit;
}

//$script2run = '
//   var opts = { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" };
//   window.cm_items  = CodeMirror.fromTextArea(document.getElementById("items"), opts);
//   ';
$apage = new AA_Adminpageutil('sliceadmin','statistics');
$apage->setTitle(_m("Admin - Statistics"));
//$apage->addRequire($script2run, 'AA_Req_Load');
//$apage->addRequire('codemirror@5');
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

// ------------------------------------------------------------------------------------------
FrmTabCaption(_m('Item Statistics'));
FrmTextarea('items', _m('Items to show'), $items, 4, 60, true, _m('dash-separated list of item ids/short_ids or AA expression like {ids:{_#SLICE_ID}:d-publish_date....-gt-{timestamp:2023-08-22}}}'));

//        //$widget_definition_string = 'dte::::2';
//        $widget_definition_string = 'mch:::::::::{"1"#:"Yes","0"#:"No"}:';
//        $params       = Widget::parseClassProperties($widget_definition_string);
//        $widget_class = $params['class'];

//$w = Widget::factoryByName('mch', ['const_arr' => HITCOUNTER_OPTIONS]);
// //echo $w->getHtml(new AA_Property( 'stats', _m('Items to show'), 'string', true), (new AA_Content())->setId('admin_statistics')->setValue('stats','1324567899'));

//if ($_POST) {
//    $grabber = new AA\IO\Grabber\ObjectForm();
//    $grabber->prepare();
//    $content = $grabber->getItem();
//    $grabber->finish();
//    huhl($grabber->getAllItems());
//} else {
//    $content = (new AA_Content())->setId('admin_statistics');
//}
//
//echo $w->getHtml(new AA_Property( 'stats', _m('Items to show'), 'string', true), $content);

FrmTabEnd($form_buttons);


echo '<br><br><div class=aa-tab-title>'._m('Display Statistics for Item').'</div>';

if (strlen($items)) {
    $items = StrExp('ids',[AA::$module_id, '','','-',AA::Un($items) ?: 'xxx']);      // get items only form this slice
    $template = '<div class=aa-table>';
    if ($items) {
        foreach (HITCOUNTER_OPTIONS as $code => $name) {
            $template .= "<h2>$name</h2>\n{hitcounter:$code:$items}\n";
        }
    }
    $template .= '</div>';
    $template .= '<div>'._m('Items found').': '. StrExp('count', [$items]).'</div>';
    //FrmStaticText(_m('Display Statistics for Item'), AA::Un($template), '', '', false);
    echo AA::Un($template);
}

echo getFrmTabEnd($form_buttons);

$apage->printFoot();
