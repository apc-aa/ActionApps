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

require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/optimize.class.php3";

if (!IsSuperadmin()) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You don't have permissions to do optimize tests."), "admin");
    exit;
}

set_time_limit(max(300,ini_get('max_execution_time')));   // 300 seconds (at least)

$Msg = '';

// php4 returns class names in lower case, so we need itin lower case
if ($_GET['test'] AND (strpos(strtolower($_GET['test']), 'aa_optimize_')===0)) {
    /** @var AA_Optimize $optimizer */
    $optimizer = AA_Serializable::factory($_GET['test']);
    $optimizer->test();
    $Msg .= $optimizer->report();
}

if ($_GET['repair'] AND (strpos(strtolower($_GET['repair']), 'aa_optimize_')===0)) {
    $optimizer = AA_Serializable::factory($_GET['repair']);
    $optimizer->repair();
    $Msg .= $optimizer->report();
}

$optimize_names        = [];
$optimize_descriptions = [];

foreach (AA_Components::getClassNames('AA_Optimize_') as $optimize_class) {
    // call static class methods
    $optimize_names[]        = $optimize_class::name();
    $description             = $optimize_class::description();
    $actions                 = $optimize_class::actions();

    $row = "
    <div>
      <div style=\"float: right;\">";
    if (in_array('test', $actions)) {
        $row .= "<a href=\"". StateUrl("?test=$optimize_class") ."\" class=aa-button-test>". _m('Test'). "</a> ";
    }
    if (in_array('repair', $actions)) {
        $row .= "<a href=\"". StateUrl("?repair=$optimize_class") ."\" class=aa-button-run>". _m('Repair'). "</a> ";
    }

    $optimize_descriptions[] = $row ."
      </div>
      <div>$description</div>
    </div>";
}

$apage = new AA_Adminpageutil('aaadmin','optimize');
$apage->setTitle(_m("Admin - Optimize a Repair ActionApps"));
$apage->printHead($err, $Msg);

//$form_buttons = array ("submit");
$form_buttons   = [];
//$destinations = array_flip(array_unique($COLNODO_DOMAINS));

echo getFrmTabCaption(_m('Optimalizations'), $form_buttons, 'class="aa-table"');
foreach ( $optimize_names as $i => $name ) {
    FrmStaticText($name, $optimize_descriptions[$i], '', '', false);
}
FrmTabEnd($form_buttons);

$apage->printFoot();
