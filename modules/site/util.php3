<?php
//$Id$
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

// Miscellaneous utility functions for "site" module

function SiteAdminPage($spot_id, $add = null) {
    global $slice_id;
    $url = get_url($_SERVER['PHP_SELF'], ['spot_id'=>$spot_id, 'slice_id'=>$slice_id]);   // Always used this way
    if ($add) {
        $url = get_url($url, $add);
    }
    // Don't add AA_Session if already there (it isn't in PHP_SELF)
    return myspecialchars( StateUrl($url) );
}

function ModW_HiddenRSpotId() {
    print('<input type="hidden" name="spot_id" value="'.$GLOBALS['r_spot_id'].'">');
}

// This function does nothing and is used when walking the tree fixing it
function ModW_DoNothing($spot_id,$depth) {
    return;
}

function ModW_SpotHtml($spot_id, $spot_name, $selected, $disabled) {
    // get class for this spot div
    $class  = ($selected) ? ' class="selected"' : '';

    // get actions (eye for disable/enable, at this moment
    $action = $disabled ?
        '<a href="'. SiteAdminPage($spot_id, 'akce=e'). '">' .GetModuleImage('site', 'disabled.gif', '', 16, 12, 'class="eye"') .'</a>' :
        '<a href="'. SiteAdminPage($spot_id, 'akce=h'). '">' .GetModuleImage('site', 'enabled.gif',  '', 16, 12, 'class="eye"') .'</a>';

    // print the spot <div>
    echo "<div$class title=\"spot ID: $spot_id\">${action}<a href=\"". SiteAdminPage($spot_id, "go_sid=$spot_id"). "\" class=\"spot\">$spot_name</a></div>";
}

function ModW_PrintSpotName_Start($spot_id, $depth) {
    global $r_spot_id, $tree;

    // print the spot itself
    ModW_SpotHtml($spot_id, $tree->getName($spot_id), $spot_id == $r_spot_id, ($tree->get('flag', $spot_id) & AA_Module_Site::FLAG_DISABLE));

    // variables defined? - print it
    if (!$tree->isFlag($spot_id, AA_Module_Site::FLAG_COLLAPSE) AND ($vars = $tree->get('variables', $spot_id))) {
        echo "\n  <div class=\"variables\">(". implode($vars, ',') .')</div>';
    }
}

function ModW_PrintChoice_Start($spot_id, $depth, $choices_index, $choices_count) {
    global $tree;
    // begin of the choice (open <div>)

    // last choice in the list is special
    $last  = ($choices_index == $choices_count-1);    // just shortcut variable
    $class = $last ? 'lastchoice' : 'choice';

    if ($tree->isLeaf($spot_id)) {
        $colaps = GetModuleImage('site', $last ? 'l.gif' : 't.gif', '', 21, 13);
    } elseif ($tree->get('flag', $spot_id) & AA_Module_Site::FLAG_COLLAPSE) {
        $colaps = '<a href="'. SiteAdminPage($spot_id, "akce=m").'">'. GetModuleImage('site', 'plus.gif', '', 21, 13) .'</a> ';
    } else {
        $colaps = '<a href="'. SiteAdminPage($spot_id, "akce=p").'">'. GetModuleImage('site', 'minus.gif', '', 21, 13) .'</a> ';
    }
    echo "\n<div class=\"$class\">$colaps";

    // print conditions
    $conditions = '';
    if ($conds = $tree->get('conditions', $spot_id)) {
        $delim = ' ';
        foreach ($conds as $k => $v) {
            $match = AA_Site_Spot_Match::factoryByString($v,$k);
            $conditions .= "$delim$k: <strong>$match->val</strong> <small>($match->op)</small>";
            $delim = ', ';
        }
    }
    if ($conditions == '') {
        $conditions = '&nbsp;';
    }
    echo "\n  <div class=\"conditions\"><a href=\"". SiteAdminPage($spot_id, "go_sid=$spot_id"). "\">$conditions</a></div>";
}

function ModW_PrintChoice_End($spot_id, $depth, $choices_index, $choices_count) {
    // close previously opened <div class="choice">
    echo "\n</div>";
}

function ModW_PrintVariables( $spot_id, $vars ) {
    echo "<tr><td valign=top><b>"._m("Spot&nbsp;variables")."</b></td><td>";
    if (isset($vars) AND is_array($vars)) {
        foreach ($vars as $k => $v) {
            echo "$v <span><a href=\"". SiteAdminPage($spot_id, "delvar=". urlencode($k)) ."\">"._m("Delete")."</a></span><br>";
        }
    }
    echo "<form name=fvar action=\"".$_SERVER['PHP_SELF']."\"><input type='text' name='addvar' value='' size='50'><span><a href='javascript:document.fvar.submit()'>"._m("Add")."</a></span>";
    ModW_HiddenRSpotId();
    echo StateHidden();
    echo "</form></td></tr>";
}

function ModW_PrintConditions($spot_id, $conds, $vars) {
    echo "<tr><td valign=top><b>"._m("Spot&nbsp;conditions")."</b></td><td>";
    if ( isset($vars) AND is_array($vars) ) {
        $i=0;
        foreach ($vars as $k => $v) {
            if ($conds[$v]) {
                $match = AA_Site_Spot_Match::factoryByString($conds[$v],$v);
                $warning = (trim($match->val) == $match->val) ? '' : '<div style="color:red;"><small>'._m('Warning: the condition starts or ends with whitespace character. Please check, if it is OK in your expression.').'</small></div>';
                echo "$v ($match->op) <strong>$match->val</strong> <span><a href=\"". SiteAdminPage($spot_id, 'delcond='. urlencode($v)) ."\">"._m("Delete")."</a></span>$warning<br>";
            } else {
                echo "<form name=fcond$i action=\"". $_SERVER['PHP_SELF'] ."\">
                         $k
                         <select name=addcondop>
                           <option>=</option>
                           <option value=contains>"._m('contains')."</option>
                           <option value=REGEXP>"._m('Regular Expression')."</option>
                         </select>
                         <input type='text' name='addcond' value='' size='50'>
                     <input type='hidden' name='addcondvar' value='$v'>
                     <span><a href='javascript:document.fcond$i.submit()'>"._m("Add")."</a></span>";
                echo StateHidden();
                ModW_HiddenRSpotId();
                echo "</form>";
            }
            $i++;
        }
    }
    echo "</td></tr>";
}

