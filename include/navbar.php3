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
# $slices - should be defined

if( !$slice_id )
  $r_slice_headline = L_NEW_SLICE_HEAD;
  
if( $editor_page )
  $nb_context = L_ARTICLE_MANAGER;
 elseif( $settings_page )
  $nb_context = L_ADMIN;
 elseif( $usermng_page )
  $nb_context = L_USER_MANAGEMENT;

$nb_manager = ( (!$slice_id OR $editor_page)  ? 
  '<span class=nbdisable>'. L_ARTICLE_MANAGER .'</span>':
  '<a href="'. $sess->url("index.php3?Tab=app"). '"><span class=nbenable>'. L_ARTICLE_MANAGER .'</span></a>');

$nb_additem = (( !$slice_id ) ?
  '<span class=nbdisable>'. L_ADD_NEW_ITEM .'</span>':
  '<a href="'. con_url($sess->url("itemedit.php3"),"encap=false&add=1"). '"><span class=nbenable>'. L_ADD_NEW_ITEM .'</span></a>');

$nb_settings = ( (!$slice_id OR $settings_page OR !IfSlPerm(PS_EDIT) ) ?
  '<span class=nbdisable>'. L_SETTINGS .'</span>':
  '<a href="'. $sess->url("slicedit.php3") .'"><span class=nbenable>'. L_SETTINGS .'</span></a>');

$nb_view = (!$slice_id ?
  '<span class=nbviewlp>'. L_VIEW_SLICE .'</span>' :
  "<a href=\"$r_slice_view_url\"><span class=nbviewlp>". L_VIEW_SLICE .'</span></a>');

$nb_logo = '<a href="'. $sess->url(self_base()."index.php3") .'"><span class=nblogo>'. L_LOGO .'</span></a>';

$nb_go = '<span class=nbenable>'. L_GO .'</span>';

$nb_usermng = ( (!$slice_id OR !IfSlPerm(PS_NEW_USER) OR $usermng_page) ?
  '<span class=nbdisable>'. L_USER_MANAGEMENT .'</span>' :
  '<a href="'. $sess->url("um_uedit.php3") .'"><span class=nbenable>'. L_USER_MANAGEMENT .'</span></a>');

/*
$Log$
Revision 1.4  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.3  2000/07/12 14:38:19  kzajicek
Switch to slice printed only when meaningful

Revision 1.2  2000/07/03 15:00:14  honzam
Five table admin interface. 'New slice expiry date bug' fixed.

Revision 1.1.1.1  2000/06/21 18:40:42  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:25  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.7  2000/06/12 19:58:36  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.6  2000/06/09 15:14:12  honzama
New configurable admin interface

Revision 1.5  2000/05/30 09:11:39  honzama
MySQL permissions upadted and completed.

Revision 1.4  2000/03/29 15:54:47  honzama
Better Netscape Navigator javascript support, new direct feeding support, minor changes in texts and look.

Revision 1.3  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>

<form name=nbform enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<TABLE border=0 cellpadding=0 cellspacing=0>
  <TR>
    <TD><IMG src="../images/spacer.gif" width=122 height=1></TD>
    <TD><IMG src="../images/spacer.gif" width=300 height=1></TD>
    <TD><IMG src="../images/spacer.gif" width=267 height=1></TD>
  </TR>
  <TR>
    <TD bgcolor="#584011" align=center> <?php echo $nb_logo; ?></td>
    <TD height=43 colspan=2 align=center valign=middle class=slicehead bgcolor=#EBDABE><?php echo "$nb_context  -  $r_slice_headline "; ?></TD>
  </TR>
  <TR>
    <TD bgcolor=#584011 align=center> <?php echo $nb_view ?> </td>
    <td align=center class=navbar><?php echo "$nb_additem | $nb_manager | $nb_settings | $nb_usermng "; ?></td>
    <TD align=center class=navbar><?php
      if( is_array($slices) AND (count($slices) > 1) ) {
        echo "<span class=nbdisable> &nbsp;". L_SWITCH_TO ."&nbsp; </span>";
        echo "<select name=slice_id onChange='document.location=\"" .con_url($sess->url($PHP_SELF),"slice_id=").'"+this.options[this.selectedIndex].value\'>';	
        reset($slices);
        while(list($k, $v) = each($slices)) { 
          echo "<option value=\"". htmlspecialchars($k)."\"";
          if ( ($slice_id AND (string)$slice_id == (string)$k)) 
            echo " selected";
          echo "> ". htmlspecialchars($v) ." </option>";
        }
        if( !$slice_id )   // new slice
          echo '<option value="new" selected> '. L_NEW_SLICE_HEAD .'</option>';
        echo "</select>\n";
      } else
        echo "&nbsp;"; ?>
    </TD>
  </TR>
</TABLE>
</form>
