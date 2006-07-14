<!-- left navigate column    -->
<?php
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

/*
$Log$
Revision 1.8  2001/06/24 16:46:22  honzam
new sort and search possibility in admin interface

Revision 1.7  2001/05/29 19:14:58  honzam
copyright + AA logo changed

Revision 1.6  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.5  2001/02/26 17:22:30  honzam
color profiles, itemmanager interface changes

Revision 1.4  2001/02/23 11:18:04  madebeer
interface improvements merged from wn branch

Revision 1.3  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.2  2000/07/03 15:00:14  honzam
Five table admin interface. 'New slice expiry date bug' fixed.

Revision 1.1.1.1  2000/06/21 18:40:41  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:24  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.5  2000/06/12 21:41:24  madebeer
removed whitespace from config-ecn.inc
added $Id $Log and $Copyright to some stray files

*/
?>
<table width="122" border="0" cellspacing="0" bgcolor="<?php echo COLOR_TABBG ?>" cellpadding="1" align="LEFT" class="leftmenu">
  <tr><td>&nbsp;</td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td class=leftmenu><?php echo L_OTHER_ARTICLES ?></td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <?php
  if( $slice_id AND ($r_bin_state != "app"))
    echo '<tr><td><img src="../images/spacer.gif" width=5 height=1 border=0 alt=""><a href="'. $sess->url("index.php3?Tab=app"). '" class=leftmenuy>'. L_ACTIVE_BIN .'</a> ('.$item_bin_cnt[1].')</td></tr>';
   else
    echo '<tr><td class=leftmenun><img src="../images/spacer.gif" width=5 height=1 border=0 alt="">'. L_ACTIVE_BIN .' ('.$item_bin_cnt[1].')</td></tr>';
  if( !$apple_design ) {
    if( $slice_id AND ($r_bin_state != "appb"))
      echo '<tr><td><img src="../images/spacer.gif" width=5 height=1 border=0 alt=""><a href="'. $sess->url("index.php3?Tab=appb"). '" class=leftmenuy>'. L_ACTIVE_BIN_PENDING_MENU .'</a></td></tr>';
     else
      echo '<tr><td class=leftmenun><img src="../images/spacer.gif" width=5 height=1 border=0 alt="">'. L_ACTIVE_BIN_PENDING_MENU .'</td></tr>';
    if( $slice_id AND ($r_bin_state != "appc"))
      echo '<tr><td><img src="../images/spacer.gif" width=5 height=1 border=0 alt=""><a href="'. $sess->url("index.php3?Tab=appc"). '" class=leftmenuy>'. L_ACTIVE_BIN_EXPIRED_MENU .'</a></td></tr>';
     else
      echo '<tr><td class=leftmenun><img src="../images/spacer.gif" width=5 height=1 border=0 alt="">'. L_ACTIVE_BIN_EXPIRED_MENU .'</td></tr>';
  }  
  if( $slice_id AND ($r_bin_state != "hold")) 
    echo '<tr><td><img src="../images/spacer.gif" width=5 height=1 border=0 alt=""><a href="'. $sess->url("index.php3?Tab=hold"). '" class=leftmenuy>'. L_HOLDING_BIN .'</a> ('.$item_bin_cnt[2].')</td></tr>';
   else
    echo '<tr><td class=leftmenun><img src="../images/spacer.gif" width=5 height=1 border=0 alt="">'. L_HOLDING_BIN .' ('.$item_bin_cnt[2].')</td></tr>';
  if( $slice_id AND ($r_bin_state != "trash")) 
    echo '<tr><td><img src="../images/spacer.gif" width=5 height=1 border=0 alt=""><a href="'. $sess->url("index.php3?Tab=trash"). '" class=leftmenuy>'. L_TRASH_BIN .'</a> ('.$item_bin_cnt[3].')</td></tr>';
   else
    echo '<tr><td class=leftmenun><img src="../images/spacer.gif" width=5 height=1 border=0 alt="">'. L_TRASH_BIN .' ('.$item_bin_cnt[3].')</td></tr>';?>
  <tr><td>&nbsp;</td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td class=leftmenu><?php echo L_MISC ?></td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <?php
/*  if( $r_bin_show == "long")
    echo "<tr><td><img src='../images/spacer.gif' width=5 height=1 border=0 alt=''><a href=\"". con_url($sess->url($PHP_SELF),"More=short")."\" class=leftmenuy>".L_LESS_DETAILS."</a></td></tr>";
   else
    echo "<tr><td><img src='../images/spacer.gif' width=5 height=1 border=0 alt=''><a href=\"". con_url($sess->url($PHP_SELF),"More=long")."\" class=leftmenuy>".L_MORE_DETAILS."</a></td></tr>";
*/
  if( ($slice_id AND IfSlPerm(PS_DELETE_ITEMS) ))
    echo '<tr><td><img src="../images/spacer.gif" width=5 height=1 border=0 alt=""><a href="'. $sess->url("index.php3?Delete=trash") .  '" class=leftmenuy>'. L_DELETE_TRASH .'</a></td></tr>';?>
  <tr><td align=center height=10><img src="../images/black.gif" width=120 height=1></td></tr><?php
  echo "<tr><td><img src='../images/spacer.gif' width=5 height=1 border=0 alt=''><a href=\"javascript:SelectVis(true)\" class=leftmenuy>".L_SELECT_VISIBLE."</a></td></tr>";
  echo "<tr><td><img src='../images/spacer.gif' width=5 height=1 border=0 alt=''><a href=\"javascript:SelectVis(false)\" class=leftmenuy>".L_UNSELECT_VISIBLE."</a></td></tr>";?>
  <tr><td height=110>&nbsp;</td></tr>
  <tr><td class=copymsg height=5>&nbsp;</td></tr>
  <?php echo '<tr><td class=copymsg><small>'. L_COPYRIGHT .'</small><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br><br></td></tr>';?>
</table>
