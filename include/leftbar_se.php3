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
Revision 1.6  2001/01/31 02:46:03  madebeer
moved Fields leftbar section back up to Slice main settings section.
updated some english language titles

Revision 1.5  2001/01/08 13:31:58  honzam
Small bugfixes

Revision 1.4  2000/12/23 19:56:50  honzam
Multiple fulltext item view on one page, bugfixes from merge v1.2.3 to v1.5.2

Revision 1.3  2000/11/16 11:48:39  madebeer
11/16/00 a- changed admin leftbar menu order and labels
         b- changed default article editor field order & fields
         c- improved some of the english labels

Revision 1.2  2000/07/17 13:28:55  kzajicek
Language changes

Revision 1.1.1.1  2000/06/21 18:40:41  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:24  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.4  2000/06/12 21:41:24  madebeer
removed whitespace from config-ecn.inc
added $Id $Log and $Copyright to some stray files

*/
?>
<table width="122" border="0" cellspacing="0" bgcolor="#EBDABE" cellpadding="1" align="LEFT">
  <tr><td>&nbsp;</td></tr>
  <tr><td valign="TOP">
  <?php
  if( $slice_id )
    echo   '&nbsp;&nbsp;<a href="'. con_url($sess->url("itemedit.php3"),"encap=false&add=1"). '" class=leftmenuy>'. L_ADD_NEW_ITEM .'</a>';
   else 
    echo   '&nbsp;&nbsp;<span class=leftmenun>'. L_ADD_NEW_ITEM ."</span></td>";?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( IfSlPerm(PS_ADD) )
    echo   '&nbsp;&nbsp;<a href="'. $sess->url("sliceadd.php3"). '" class=leftmenuy>'. L_NEW_SLICE .'</a>';
   else 
    echo   '&nbsp;&nbsp;<span class=leftmenun>'. L_NEW_SLICE ."</span></td>";?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( ($slice_id AND IfSlPerm(PS_DELETE_ITEMS) ))
    echo   '&nbsp;&nbsp;<a href="'. $sess->url("index.php3?Delete=trash") .  '" class=leftmenuy>'. L_DELETE_TRASH .'</a>';
   else 
    echo   '&nbsp;&nbsp;<span class=leftmenun>'. L_DELETE_TRASH . "</span></td>";?>
  </tr>
  <tr><td>&nbsp;</td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td class=leftmenu><?php echo L_MAIN_SET ?></td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td width="122" valign="TOP">
  <?php
  if( $show["main"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT)) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("slicedit.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_SLICE_SET."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_SLICE_SET ."</span></td>"; ?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["category"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CATEGORY) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_constant.php3") ."&slice_id=$slice_id&category=1\" class=leftmenuy>".L_CATEGORY."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_CATEGORY ."</span></td>"; ?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["fields"]  AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS)) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_fields.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_FIELDS."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_FIELDS ."</span></td>"; ?>
  </tr>

  <tr><td>&nbsp;</td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td class=leftmenu><?php echo L_PERMISSIONS ?></td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td valign="TOP">
  <?php
  if( $show["users"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_USERS) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_users.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_PERM_CHANGE."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_PERM_CHANGE ."</span></td>"; ?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["addusers"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ADD_USER) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_users.php3") ."&adduser=1&slice_id=$slice_id\" class=leftmenuy>".L_PERM_ASSIGN."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_PERM_ASSIGN ."</span></td>"; ?>
  </tr>
  <tr><td>&nbsp;</td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td class=leftmenu><?php echo L_DESIGN ?></td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td valign="TOP">
  <?php
  if( $show["compact"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_COMPACT) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_compact.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_COMPACT."</a></td>"; 
   else 
    echo "<span class=leftmenun>&nbsp;&nbsp;". L_COMPACT ."</span></td>";?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["fulltext"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_fulltext.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_FULLTEXT."</a></td>"; 
   else 
    echo "<span class=leftmenun>&nbsp;&nbsp;". L_FULLTEXT ."</span></td>";?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["search"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_SEARCH) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_search.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_SEARCH_SET."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_SEARCH_SET ."</span></td>"; ?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["config"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CONFIG) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_admin.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_SLICE_CONFIG."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_SLICE_CONFIG ."</span></td>"; ?>
  </tr>
  <tr><td>&nbsp;</td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td class=leftmenu><?php echo L_FEEDING ?></td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td valign="TOP">
  <?php
  if( $show["import"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_import.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_IMPORT."</a></td>"; 
   else 
    echo "<span class=leftmenun>&nbsp;&nbsp;". L_IMPORT ."</span></td>";?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["filters"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_filters.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_FILTERS."</a></td>"; 
   else 
    echo "<span class=leftmenun>&nbsp;&nbsp;". L_FILTERS ."</span></td>";?>
  </tr>
  <tr><td>&nbsp;</td></tr>
  <tr><td height=110>&nbsp;</td>
  </tr>
</table>
