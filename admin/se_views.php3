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

# expected $view_id for editing specified view

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";     // GetAliasesFromField funct def 
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_VIEWS);
  exit;
}  

function PrintView($id, $name, $type) {
  global $sess, $VIEW_TYPES;

  $name=safe($name); $id=safe($id);     
  
  echo "<tr class=tabtxt><td>$id</td>
          <td>$id</td>
          <td>$name</td>
          <td>". $VIEW_TYPES[$type]["name"] ."</td>
          <td><a href=\"". con_url($sess->url("./se_view.php3"),
            "view_id=$id&view_type=$type"). "\">". L_EDIT . "</a></td></tr>";
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$p_slice_id = q_pack_id($slice_id);

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
echo "<TITLE>". L_A_VIEW_TIT ."</TITLE>
      </HEAD>";

$xx = ($slice_id!="");
$useOnLoad = ($new_compact ? true : false);
$show = Array("main"=>true, "slicedel"=>$xx, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx, 
              "views"=>false, "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "filters"=>$xx);
require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable

echo "<H1><B>" . L_A_VIEWS . "</B></H1>";
PrintArray($err);
echo $Msg;
?>
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_VIEWS_HDR?></b><BR>
</td></tr>
<tr><td>
<form name="fvtype" action="<?php echo $sess->url("./se_view.php3") ?>">
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  
# -- get views for current slice --
$SQL = "SELECT * FROM view WHERE slice_id='$p_slice_id'";
$db->query($SQL);
while( $db->next_record() )
  PrintView($db->f(id), $db->f(name), $db->f(type));

  # row for new view
echo "<tr class=tabtit><td align=center colspan=4>
      <select name='view_type'>";	
reset($VIEW_TYPES);
while(list($k, $v) = each($VIEW_TYPES)) { 
  echo "<option value='$k'> ". htmlspecialchars($v["name"]) ." </option>";
}
echo "</select>
      <input type=submit name=new value='". L_NEW ."'>
  </table></form></td></tr></table><br>";

$viewuri = ereg_replace("/admin/.*", "/view.php3", $PHP_SELF); #include help
echo L_SLICE_HINT ."<br><pre>&lt;!--#include virtual=&quot;" . $viewuri . 
         '?v=<i>ID</i>&quot;--&gt;</pre>
 </BODY></HTML>';
page_close();

/*
$Log$
Revision 1.5  2001/05/10 10:01:43  honzam
New spanish language files, removed <form enctype parameter where not needed, better number validation

Revision 1.4  2001/03/30 11:52:53  honzam
reverse displaying HTML/Plain text bug and others smalll bugs fixed

Revision 1.3  2001/03/20 15:27:03  honzam
Changes due to "slice delete" feature

Revision 1.2  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.1  2001/02/26 17:26:08  honzam
color profiles

*/
?>