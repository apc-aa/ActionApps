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
?>
<table width="122" border="0" cellspacing="0" bgcolor="<?php echo COLOR_TABBG ?>" cellpadding="1" align="LEFT" class="leftmenu">
  <tr><td>&nbsp;</td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td class=leftmenu><?php echo L_OTHER_ARTICLES ?></td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <?php
  if( $slice_id AND ($r_bin_state != "app"))
    echo '<tr><td class=leftmenuy><img src="../images/spacer.gif" width=5 height=1 border=0 alt=""><a href="'. $sess->url("index.php3?Tab=app"). '" class=leftmenuy>'. L_ACTIVE_BIN .'</a> ('.($item_bin_cnt[1]-$item_bin_cnt_exp-$item_bin_cnt_pend).')</td></tr>';
   else
    echo '<tr><td class=leftmenun><img src="../images/spacer.gif" width=5 height=1 border=0 alt="">'. L_ACTIVE_BIN .' ('.($item_bin_cnt[1]-$item_bin_cnt_exp-$item_bin_cnt_pend).')</td></tr>';
  if( !$apple_design ) {
    if( $slice_id AND ($r_bin_state != "appb"))
      echo '<tr><td class=leftmenuy><img src="../images/spacer.gif" width=5 height=1 border=0 alt=""><a href="'. $sess->url("index.php3?Tab=appb"). '" class=leftmenuy>'. L_ACTIVE_BIN_PENDING_MENU ."</a> ($item_bin_cnt_pend)</td></tr>";
     else
      echo '<tr><td class=leftmenun><img src="../images/spacer.gif" width=5 height=1 border=0 alt="">'. L_ACTIVE_BIN_PENDING_MENU ." ($item_bin_cnt_pend)</td></tr>";
    if( $slice_id AND ($r_bin_state != "appc"))
      echo '<tr><td class=leftmenuy><img src="../images/spacer.gif" width=5 height=1 border=0 alt=""><a href="'. $sess->url("index.php3?Tab=appc"). '" class=leftmenuy>'. L_ACTIVE_BIN_EXPIRED_MENU ."</a> ($item_bin_cnt_exp)</td></tr>";
     else
      echo '<tr><td class=leftmenun><img src="../images/spacer.gif" width=5 height=1 border=0 alt="">'. L_ACTIVE_BIN_EXPIRED_MENU ." ($item_bin_cnt_exp)</td></tr>";
  }  
  if( $slice_id AND ($r_bin_state != "hold")) 
    echo '<tr><td class=leftmenuy><img src="../images/spacer.gif" width=5 height=1 border=0 alt=""><a href="'. $sess->url("index.php3?Tab=hold"). '" class=leftmenuy>'. L_HOLDING_BIN .'</a> ('.$item_bin_cnt[2].')</td></tr>';
   else
    echo '<tr><td class=leftmenun><img src="../images/spacer.gif" width=5 height=1 border=0 alt="">'. L_HOLDING_BIN .' ('.$item_bin_cnt[2].')</td></tr>';
  if( $slice_id AND ($r_bin_state != "trash")) 
    echo '<tr><td class=leftmenuy><img src="../images/spacer.gif" width=5 height=1 border=0 alt=""><a href="'. $sess->url("index.php3?Tab=trash"). '" class=leftmenuy>'. L_TRASH_BIN .'</a> ('.$item_bin_cnt[3].')</td></tr>';
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
