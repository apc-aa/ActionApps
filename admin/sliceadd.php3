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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/


// messages for init_page:
$no_slice_id          = true;
$require_default_lang = true;

require_once __DIR__."/../include/init_page.php3";
/** GetModuleTemplateSelecbox function
 * @param $type
 * @param $g_modules
 * @return string - an html select box or false if g_modules is null or not an array
 */
function GetModuleTemplateSelecbox($type, $g_modules) {
    $ret = '';
    if (isset($g_modules) AND is_array($g_modules) ) {
        foreach ($g_modules as $mid => $mod) {
            if ( $mod['type']==$type ) {
                $ret .=  "\n  <option value=\"x$mid\">".$mod['name']."</option>";
            }
        }
    }
    return $ret ? "<select name=\"template[$type]\">$ret\n</select>" : false;
}


// the parts used by the slice wizard are in the included file

if ($cancel) {
  go_url( StateUrl(self_base() . "index.php3"));
}

$err = [];          // error array (Init - just for initializing variable

$apage = new AA_Adminpageutil();
$apage->setTitle(_m("Create New Slice / Module"));
$apage->setForm(['action'=>'slicedit.php3']);
$apage->printHead($err, $Msg);

require_once __DIR__."/../include/sliceadd.php3";

FrmTabCaption(_m("Modules"));
foreach ($MODULES as $type => $module) {
  if ($module["hide_create_module"]) {
        continue;
    }
    if ($module["show_templates"]) {
        $templ_sb = GetModuleTemplateSelecbox($type, $g_modules);
        if (!$templ_sb) {
            continue;
        }
    }
    echo "<tr><td width=\"20%\" class=\"tabtxt\"><b>"._mdelayed($module['name'])."</b></td><td width=\"60%\">".
         ($module["show_templates"] ? $templ_sb : "&nbsp;").
         "</td><td width=\"60%\">
        <input type=\"submit\" name=\"create[$type]\" value=\""._m("Add")."\"></td></tr>";
}
FrmTabEnd();

echo '<br><br>'. getTabStart();

echo getFrmInputButtons(["cancel" => ["url" => "aafinder.php3"]], 'middle', true, COLOR_TABTITBG);
FrmTabEnd();

$apage->printFoot();
