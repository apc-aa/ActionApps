<?php // navbar - application navigation bar for the module
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

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

// $slice_id - should be defined
// $r_slice_view_url - should be defined
// $editor_page or $usermng_page or $settings_page - should be defined
// $g_modules - should be defined



// This is the definition of main navigation bar. The navigation bar can be
// slightly different for the modules, but in general it should look the
// way for all the modules. There should be APC-AA logo, name of page, module
// switching dropdown menu, ...

if ( $editor_page ) {
    $nb_context = _m("Code Manager");
} elseif( $settings_page ) {
    $nb_context = _m("Module Settings");
} elseif( $usermng_page ) {
    $nb_context = _m("Users");
}

// modules are in directory one level deeper than scripts in /admin/...
// if the '/admin' is in path, this navbar is called just after swithing to
// this module - it is called from slice's /admin directory
$nb_backpath = ( (strpos($_SERVER['PHP_SELF'], '/admin/') > 0 ) ? '' : '../' );

$nb_manager = ( $editor_page ?
  '<span class=nbdisable>'. _m("Code&nbsp Manager") .'</span>':
  '<a href="'. $sess->url("index.php3"). '"><span class=nbenable>'. _m("Code Manager") .'</span></a>');

$nb_settings = ( ( $settings_page OR !IfSlPerm(PS_MODW_SETTINGS) ) ?
  '<span class=nbdisable>'. _m("Module Settings") .'</span>':
  '<a href="'. $sess->url($MODULES[$g_modules[$module_id]['type']]['directory']. "slicedit.php3") .'"><span class=nbenable>'. _m("Module Settings") .'</span></a>');

$nb_view = (!$r_slice_view_url ?
  '<span class=nbenable>'. _m("View site") .'</span>' :
  " &nbsp; &nbsp;<a href=\"$r_slice_view_url\"><span class=nbenable>". _m("View site") .'</span></a>');

$nb_logo = '<a href="'. AA_INSTAL_PATH .'"><img src="'.$nb_backpath.'../images/action.gif" width="106" height="73" border="0" alt="'. _m("ActionApps") .'"></a>';

$nb_go = '<span class=nbenable>'. _m("Go") .'</span>';

$nb_usermng = ( (!IfSlPerm(PS_NEW_USER) OR $usermng_page) ?
  '<span class=nbdisable>'. _m("Users") .'</span>' :
  '<a href="'. $sess->url("um_uedit.php3") .'"><span class=nbenable>'. _m("Users") .'</span></a>');

echo "
<TABLE border=0 cellpadding=0 cellspacing=0>
  <TR>
    <TD><IMG src=\"$nb_backpath../images/spacer.gif\" width=122 height=1></TD>
    <TD><IMG src=\"$nb_backpath../images/spacer.gif\" width=300 height=1></TD>
    <TD><IMG src=\"$nb_backpath../images/spacer.gif\" width=267 height=1></TD>
  </TR>
  <TR>
    <TD rowspan=2 align=center class=nblogo>$nb_logo</td>
    <TD height=43 colspan=2 align=center valign=middle class=slicehead>
    $nb_context  -  ".
    ($module_id ? AA_Slice::getModuleName($module_id) : _m("New slice"))
    ."</TD>
  </TR>
  <TR>
    <td align=center class=navbar>
     $nb_view | $nb_manager | $nb_settings | $nb_usermng </td>
    <TD align=center class=navbar>";

PrintModuleSelection();

echo "</TD></TR></TABLE>";
?>