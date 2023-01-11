<?php
 /**
 *
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
 * @package   Maintain
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
*/

use AA\Optimize\AbstractOptimize;

// const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";

if (!IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php3", _m("You don't have permissions to do optimize tests."), "admin");
    exit;
}

set_time_limit(max(300,ini_get('max_execution_time')));   // 300 seconds (at least)

$Msg = '';
$testresult = [];
$err = [];          // error array (Init - just for initializing variable

/** @var AbstractOptimize $optimizer */
if ($_GET['test'] AND ($optimizer = AA_Serializable::factory('AA\\Optimize\\'.$_GET['test'])) AND $optimizer->hasAction('test')) {
    $testresult[$_GET['test']] = $optimizer->test();
    $Msg .= $optimizer->report();
}

/** @var AbstractOptimize $optimizer */
if ($_GET['test4modules'] AND ($optimizer = AA_Serializable::factory('AA\\Optimize\\'.$_GET['test4modules'])) AND $optimizer->hasAction('test4modules')) {
    $testresult[$_GET['test4modules']] = $optimizer->test4modules([AA::$module_id]);
    $Msg .= $optimizer->report();
}

/** @var AbstractOptimize $optimizer */
if ($_GET['repair'] AND ($optimizer = AA_Serializable::factory('AA\\Optimize\\'.$_GET['repair'])) AND $optimizer->hasAction('repair')) {
    $optimizer->repair();
    $Msg .= $optimizer->report();
    $testresult[$_GET['repair']] = $optimizer->test();
}

/** @var AbstractOptimize $optimizer */
if ($_GET['repair4modules'] AND ($optimizer = AA_Serializable::factory('AA\\Optimize\\'.$_GET['repair4modules'])) AND $optimizer->hasAction('repair4modules')) {
    $optimizer->repair4modules([AA::$module_id]);
    $Msg .= $optimizer->report();
    $testresult[$_GET['repair4modules']] = $optimizer->test4modules([AA::$module_id]);
}

$optimize_names        = [];
$optimize_descriptions = [];

foreach (AA_Components::getClassNames('AA\\Optimize\\') as $optimize_class) {

    // call static class methods
    $optimize_names[]        = $optimize_class::name();
    $description             = $optimize_class::description();
    $actions                 = $optimize_class::actions();
    $class_type              = AA_Components::getClassType($optimize_class);

    if ($_POST['testall']) {

        /** @var AbstractOptimize $optimizer */
        $optimizer = AA_Serializable::factory($optimize_class);
        if ($optimizer->hasAction('test')) {
            if ( !($testresult[$class_type] = $optimizer->test())) {
                $Msg .= $optimizer->report();
            }
        }
    }

    $addclass = isset($testresult[$class_type]) ? ($testresult[$class_type] ? 'class=okmsg' : 'class=err') : '';

    $row = "
    <div>
      <div style=\"float: right;\">";
    if (in_array('test4modules', $actions)) {
        $row .= "<a href=\"". StateUrl("?test4modules=$class_type") ."\" class=aa-button-test title=\"". _m('Test for current module')."\">". _m('Test current'). "</a> ";
    }
    if (in_array('repair4modules', $actions)) {
        $row .= "<a href=\"". StateUrl("?repair4modules=$class_type") ."\" class=aa-button-run title=\"". _m('Repair current module')."\">". _m('Repair current'). "</a> ";
    }

    if (in_array('test', $actions)) {
        $row .= "<a href=\"". StateUrl("?test=$class_type") ."\" class=aa-button-test>". _m('Test'). "</a> ";
    }
    if (in_array('repair', $actions)) {
        $row .= "<a href=\"". StateUrl("?repair=$class_type") ."\" class=aa-button-run>". _m('Repair'). "</a> ";
    }

    $optimize_descriptions[] = $row ."
      </div>
      <div $addclass>$description</div>
    </div>";
}

$apage = new AA_Adminpageutil('aaadmin','optimize');
$apage->setTitle(_m("Admin - Optimize a Repair ActionApps"));
$apage->printHead($err, $Msg);

//$form_buttons = array ("submit");
$form_buttons = ["testall" => ["type"=>"submit", "value"=>_m("Test All")]];

//$destinations = array_flip(array_unique($COLNODO_DOMAINS));

echo getFrmTabCaption(_m('Optimalizations'), $form_buttons, 'class="aa-table"');
foreach ( $optimize_names as $i => $name ) {
    FrmStaticText($name, $optimize_descriptions[$i], '', '', false);
}
FrmTabEnd($form_buttons);

$apage->printFoot();
