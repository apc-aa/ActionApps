<?php
 /**
 *
 * PHP versions 4 and 5
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
 * @link      http://www.apc.org/ APC
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH. 'formutil.php3';
require_once AA_INC_PATH. 'optimize.class.php3';

if (!IsSuperadmin()) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You don't have permissions to do optimize tests."), "admin");
    exit;
}

$Msg = '';

// php4 returns class names in lower case, so we need itin lower case
if ($_GET['test'] AND (strpos(strtolower($_GET['test']), 'aa_optimize_')===0)) {
    $optimizer = AA_Components::factory($_GET['test']);
    $optimizer->test();
    $Msg .= $optimizer->report();
}

if ($_GET['repair'] AND (strpos(strtolower($_GET['repair']), 'aa_optimize_')===0)) {
    $optimizer = AA_Components::factory($_GET['repair']);
    $optimizer->repair();
    $Msg .= $optimizer->report();
}

$optimize_names        = array();
$optimize_descriptions = array();

foreach (AA_Components::getClassNames('AA_Optimize_') as $optimize_class) {
    // call static class methods
    $optimize_names[]        = call_user_func(array($optimize_class, 'name'));
    $description             = call_user_func(array($optimize_class, 'description'));
    $actions                 = call_user_func(array($optimize_class, 'actions'));

    $row = "
    <div>
      <div style=\"float: right;\">";
    if (in_array('test', $actions)) {
        $row .= "<a href=\"". $sess->url("?test=$optimize_class") ."\">". _m('Test'). "</a> ";
    }
    if (in_array('repair', $actions)) {
        $row .= "<a href=\"". $sess->url("?repair=$optimize_class") ."\">". _m('Repair'). "</a> ";
    }

    $optimize_descriptions[] = $row ."
      </div>
      <div>$description</div>
    </div>";
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Admin - Optimize a Repair ActionApps"); ?></title>
<script Language="JavaScript"><!--
function InitPage() {}
//-->
</script>
</head>
<body>
<?php
$useOnLoad = true;
require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "aaadmin", "optimize");

echo "<H1><B>" . _m("Admin - Optimize a Repair ActionApps") . "</B></H1>";
PrintArray($err);
echo $Msg;

//$form_buttons = array ("submit");
$form_buttons   = array ();
//$destinations = array_flip(array_unique($COLNODO_DOMAINS));

?>
<form name="f" method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<?php
FrmTabCaption(_m('Optimalizations'), '','', $form_buttons, $sess, $slice_id);
foreach ( $optimize_names as $i => $name ) {
    FrmStaticText($name, $optimize_descriptions[$i], false, '', '', false);
}
FrmTabEnd($form_buttons, $sess, $slice_id);
?>
</form>
<?php
HtmlPageEnd();
page_close()
?>
