<?php # navbar - application navigation bar 
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

# $r_slice_headline - should be defined
# $slice_id - should be defined
# $r_slice_view_url - should be defined
# $editor_page or $usermng_page or $settings_page - should be defined
# $g_modules - should be defined

if( !$slice_id )
  $r_slice_headline = L_NEW_SLICE_HEAD;
  
if( $editor_page )
  $nb_context = L_ARTICLE_MANAGER;
 elseif( $settings_page )
  $nb_context = L_SLICE_ADMIN;
 elseif( $usermng_page )
  $nb_context = L_AA_ADMIN;

function get_url ($href) {
    global $AA_INSTAL_PATH;
    $res = $AA_INSTAL_PATH."admin/".$href;
    if (strstr ($href,"?")) $res .= "&"; else $res .= "?";
    $res .= "AA_CP_Session=".$GLOBALS[AA_CP_Session];
    return $res;
}
  
$nb_manager = ( (!$slice_id OR $editor_page)  ? 
  '<span class=nbdisable>'. L_ARTICLE_MANAGER .'</span>':
  '<a href="'. get_url("index.php3?Tab=app"). '"><span class=nbenable>'. L_ARTICLE_MANAGER .'</span></a>');

$nb_additem = (( !$slice_id ) ?
  '<span class=nbdisable>'. L_ADD_NEW_ITEM .'</span>':
  '<a href="'. get_url("itemedit.php3?encap=false&add=1"). '"><span class=nbenable>'. L_ADD_NEW_ITEM .'</span></a>');

$nb_settings = ( (!$slice_id OR $settings_page OR !IfSlPerm(PS_EDIT) ) ?
  '<span class=nbdisable>'. L_SLICE_ADMIN2 .'</span>':
  '<a href="'. get_url("slicedit.php3") .'"><span class=nbenable>'. L_SLICE_ADMIN2 .'</span></a>');

$nb_view = (!$slice_id ?
  '<span class=nbenable>'. L_VIEW_SLICE .'</span>' :
  " &nbsp; &nbsp;<a href=\"$r_slice_view_url\"><span class=nbenable>". L_VIEW_SLICE .'</span></a>');

$nb_logo = '<a href="'. $AA_INSTAL_PATH .'"><img src="'.$AA_INSTAL_PATH.'images/action.gif" width="106" height="73" border="0" alt="'. L_LOGO .'"></a>';

$nb_go = '<span class=nbenable>'. L_GO .'</span>';

$nb_aasettings = ( (!$slice_id OR !IfSlPerm(PS_NEW_USER) OR $usermng_page) ?
  '<span class=nbdisable>'. L_AA_ADMIN2 .'</span>' :
  '<a href="'. get_url("um_uedit.php3") .'"><span class=nbenable>'. L_AA_ADMIN2 .'</span></a>');

?>

<TABLE border=0 cellpadding=0 cellspacing=0>
  <TR>
    <TD><IMG src="<?php echo $AA_INSTAL_PATH ?>images/spacer.gif" width=122 height=1></TD>
    <TD><IMG src="<?php echo $AA_INSTAL_PATH ?>images/spacer.gif" width=300 height=1></TD>
    <TD><IMG src="<?php echo $AA_INSTAL_PATH ?>images/spacer.gif" width=267 height=1></TD>
  </TR>
  <TR>
    <TD rowspan=2 align=center class=nblogo> <?php echo $nb_logo; ?></td>
    <TD height=43 colspan=2 align=center valign=middle class=slicehead>
    <?php echo "$nb_context  -  $r_slice_headline "; ?></TD>
  </TR>
  <TR>
    <td align=center class=navbar><?php echo " $nb_view | $nb_additem | $nb_manager | $nb_settings | $nb_aasettings "; ?></td>
    <TD align=center class=navbar><?php PrintModuleSelection(); ?>
    </TD>
  </TR>
</TABLE>