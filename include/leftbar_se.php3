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

$new_slice = ($slice_id == ""); //when we have a new slice, most menu items are disabled

function SetShow ($baritem)
{
	// set true to all items which aren't set yet
	// but if we work with a new slice, set false to all those items
	global $show;
	global $new_slice;
	//if (gettype($show[$baritem])=="NULL") $show[$baritem] = ! $new_slice;
     if ( ! isset($show[$baritem]))
          $show[$baritem] = ! $new_slice; 
}
   
     if ( ! isset($show["main"])) $show["main"] = true;
     //if (gettype($show["main"])=="NULL") 

SetShow ("slicedel"); SetShow ("config"); SetShow ("category"); SetShow ("fields");
SetShow ("notify"); SetShow ("search"); SetShow ("users"); SetShow("compact"); 
SetShow ("fulltext"); SetShow ("views"); 
SetShow ("addusers"); SetShow ("newusers"); SetShow ("import"); 
SetShow ("filters"); SetShow ("n_import"); SetShow ("n_export"); SetShow ("nodes");
SetShow ("mapping"); SetShow ("sliceexp"); SetShow ("sliceimp");
/*
reset ($show);
while (list ($key, $val) = each ($show)) {
    echo "DEBUG $key => $val<br>";
    }*/
?>

<table width="122" border="0" cellspacing="0" bgcolor="<?php echo COLOR_TABBG ?>" cellpadding="1" align="LEFT" class="leftmenu">
  <tr><td>&nbsp;</td></tr>
  <tr><td valign="TOP">
  <?php
  if( IfSlPerm(PS_ADD) )
    echo   '&nbsp;&nbsp;<a href="'. $sess->url("sliceadd.php3"). '" class=leftmenuy>'. L_NEW_SLICE .'</a>';
   else 
    echo   '&nbsp;&nbsp;<span class=leftmenun>'. L_NEW_SLICE ."</span></td>";?>
  </tr>
  <tr><td valign="TOP">
  <?php 
  if( $show["slicedel"] AND IsSuperadmin() )
    echo   '&nbsp;&nbsp;<a href="'. $sess->url("slicedel.php3"). '" class=leftmenuy>'. L_DEL_SLICE .'</a>';
   else 
    echo   '&nbsp;&nbsp;<span class=leftmenun>'. L_DEL_SLICE ."</span></td>";?>
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
  <tr><td valign="TOP"> 
  <?php 
  if( $show["notify"]  AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT)) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_notify.php3")."&slice_id=$slice_id\" class=leftmenuy>".L_NOTIFY."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_NOTIFY ."</span></td>"; ?>
  </tr>
  <tr><td>&nbsp;</td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td class=leftmenu><?php echo L_PERMISSIONS ?></td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td valign="TOP">
  <?php
  if( $show["addusers"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ADD_USER) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_users.php3") ."&adduser=1&slice_id=$slice_id\" class=leftmenuy>".L_PERM_ASSIGN."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_PERM_ASSIGN ."</span></td>"; ?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["users"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_USERS) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_users.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_PERM_CHANGE."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_PERM_CHANGE ."</span></td>"; ?>
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
  if( $show["views"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_views.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_VIEWS."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_VIEWS ."</span></td>"; ?>
  </tr>
  <tr><td valign="TOP"> 
  <?php /*
  if( $show["search"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_SEARCH) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_search.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_SEARCH_SET."</a></td>"; 
   else 
    echo "&nbsp;&nbsp;<span class=leftmenun>". L_SEARCH_SET ."</span></td>"; */?>
<!--   </tr>
  <tr><td valign="TOP"> -->
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
  if( $show["nodes"] AND isSuperadmin() )
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_nodes.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_NODES_MANAGER."</a></td>";
   else
    echo "<span class=leftmenun>&nbsp;&nbsp;". L_NODES_MANAGER ."</span></td>";?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["import"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_import.php3") ."&slice_id=$slice_id\" class=leftmenuy>" ,L_INNER_IMPORT."</a></td>";
   else 
    echo "<span class=leftmenun>&nbsp;&nbsp;". L_INNER_IMPORT ."</span></td>";?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["n_import"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING) )
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_inter_import.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_INTER_IMPORT."</a></td>";
   else
    echo "<span class=leftmenun>&nbsp;&nbsp;". L_INTER_IMPORT ."</span></td>";?>
  </tr>
 <tr><td valign="TOP">
  <?php
  if( $show["n_export"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING) )
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_inter_export.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_INTER_EXPORT."</a></td>";
   else
    echo "<span class=leftmenun>&nbsp;&nbsp;". L_INTER_EXPORT ."</span></td>";?>
  </tr>

  <tr><td valign="TOP">
  <?php
  if( $show["filters"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_filters.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_FILTERS."</a></td>"; 
   else 
    echo "<span class=leftmenun>&nbsp;&nbsp;". L_FILTERS ."</span></td>";?>
  </tr>

  <tr><td valign="TOP">
  <?php
  if( $show["mapping"] AND CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING) ) 
    echo "&nbsp;&nbsp;<a href=\"". $sess->url("se_mapping.php3") ."&slice_id=$slice_id\" class=leftmenuy>".L_MAP."</a></td>"; 
   else
    echo "<span class=leftmenun>&nbsp;&nbsp;". L_MAP ."</span></td>";?>
  </tr>
  <tr><td>&nbsp;</td></tr>

  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td class=leftmenu><?php echo L_EXPIMP_SET ?></td></tr>
  <tr><td><img src="../images/black.gif" width=120 height=1></td></tr>
  <tr><td valign="TOP">
  <?php
  if( $show["sliceexp"] AND IfSlPerm(PS_ADD) )
    echo   '&nbsp;&nbsp;<a href="'. $sess->url("sliceexp.php3"). '" class=leftmenuy>'. L_EXPORT_SLICE .'</a>';
   else 
    echo   '&nbsp;&nbsp;<span class=leftmenun>'. L_EXPORT_SLICE ."</span></td>";?>
  </tr>
  <tr><td valign="TOP">
  <?php
  if( $show["sliceimp"] AND IfSlPerm(PS_ADD) )
    echo   '&nbsp;&nbsp;<a href="'. $sess->url("sliceimp.php3"). '" class=leftmenuy>'. L_IMPORT_SLICE .'</a>';
   else 
    echo   '&nbsp;&nbsp;<span class=leftmenun>'. L_IMPORT_SLICE ."</span></td>";?>
  </tr>
  <tr><td>&nbsp;</td></tr>
  <tr><td height=110>&nbsp;</td>
  </tr>
</table>
