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

# se_inter_import.php3 - Inter node feed import settings

#           $slice_id
#           $feed_id - if set, then delete this feed

# optionaly $Msg to show under <h1>Headline</h1> (typicaly: Fields' mapping update)

require "../include/init_page.php3";

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING)) {
  MsgPage($sess->url(self_base()."index.php3"), L_NO_PS_FEEDING);
  exit;
}
require $GLOBALS[AA_INC_PATH]."formutil.php3";

$p_slice_id = q_pack_id($slice_id);

if (isset($feed_id)) {
  // delete mode

  // delete mapping from feedmap table
  $db->query("SELECT remote_slice_id FROM external_feeds WHERE feed_id='$feed_id' AND slice_id='$p_slice_id'");
  if ($db->next_record()) {
    $remote_slice_id = $db->f(remote_slice_id);
    $db->query("DELETE FROM feedmap WHERE from_slice_id='$remote_slice_id' AND to_slice_id='$p_slice_id'");
  }
  $db->query("DELETE FROM ef_categories WHERE feed_id='$feed_id'");      // delete categories
  $db->query("DELETE FROM external_feeds WHERE feed_id='$feed_id'");     // delete feed
}

$db->query("SELECT feed_id, name, remote_slice_id, remote_slice_name FROM external_feeds, nodes
             WHERE external_feeds.node_name = nodes.name
               AND slice_id='$p_slice_id' ORDER BY name");
unset($ext_feeds);
while ($db->next_record()) {
  $ext_feeds[$db->f(feed_id)] = $db->Record;
}

$db->query('SELECT * FROM nodes ORDER BY name ');
unset($nodes);
while ($db->next_record()) {
  $nodes[] = $db->Record;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_IMPORT_TIT;?></TITLE>
<SCRIPT Language="JavaScript"><!--

function InitPage() {}

function SelectValue(sel) {
  return eval(sel).options[eval(sel).selectedIndex].value
}

function Delete() {
  sel = SelectValue('document.f.feed_id')
  if (sel == undefined) {
    alert('<?php echo L_IMPORT_SEL_NONE; ?>')
    return
  }
  if (!confirm('<?php echo L_IMPORT_CONFIRM_DELETE; ?>'))
    return

  var url = "<?php echo $sess->url(self_base() . "se_inter_import.php3"); ?>"
  url += "&feed_id=" + sel
  document.location = url
}

function Submit() {
  if (SelectValue(document.f.rem_nodes)==undefined) {
     alert('<?php echo L_IMPORT_NODE_SEL; ?>')
     return false
  }
}

// -->
</SCRIPT>

</HEAD>
<BODY>
<?php
  $xx = ($slice_id!="");
  $useOnLoad = true;
  $show = Array("main"=>true, "slicedel"=>$xx, "config"=>$xx, "category"=>$xx, "fields"=>$xx, "search"=>$xx, "users"=>$xx, "compact"=>$xx, "fulltext"=>$xx,
                "views"=>$xx, "addusers"=>$xx, "newusers"=>$xx, "import"=>$xx, "nodes"=>isSuperadmin(), "n_import"=>false, "n_export"=>$xx, "filters"=>$xx, "mapping"=>$xx);
  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   //show navigation column depending on $show variable
  echo "<H1><B>" . L_IMPORT_TIT . "</B></H1>";
  PrintArray($err);
  echo $Msg;

?>
<form method=post name="f" action="<?php echo $sess->url(self_base() ."se_inter_import2.php3") ?>" onSubmit="return Submit()" >
  <table width="400" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
    <tr><td class=tabtit><b>&nbsp;<?php echo L_IMPORT_TIT ?></b></td></tr>
     <tr><td>
      <table width="100%" border="0" cellspacing="0" cellpadding="2" bgcolor="<?php echo COLOR_TABBG ?>">
      <tr><td ><?php echo L_IMPORT_LIST ."<b>" .$r_slice_headline ."</b>" ?></td></tr>
      <tr><td align=center>
        <SELECT name="feed_id" size=5>
         <?php
           if ($ext_feeds && is_array($ext_feeds)) {
              reset($ext_feeds);
              while(list($k,$v) = each($ext_feeds)) {
                $str = str_replace(" ","&nbsp;",substr($v[name]."                              ",0,30)."  ");
                echo "<option value=\"$k\">".$str.$v[remote_slice_name]."</option>";
              }
           }
          ?>
        </SELECT>
      </td></tr>
      <tr><td align="center">
        <input type=button VALUE="<?php echo L_DELETE ?>" onClick = "Delete()">
       </td></tr>

      <tr><td ><?php echo L_IMPORT_NODES_LIST; ?>
      </td></tr>
      <tr><td align="center">
        <SELECT name="rem_nodes" class=tabtxt size=5>
        <?php
          reset($nodes);
          while(list(,$n) = each($nodes))
            echo "<option value=\"".$n[name]."\"".($n[name]==$node ? "selected":"")." >".$n[name]."</option>";
        ?>
        </SELECT>
      </td></tr>
      <tr><td align=center ><input type=submit value="<?php echo L_IMPORT_CREATE ?>" ></td></tr>
  </table>
  </td></tr>
  </table>
</FORM>
</BODY>
</HTML>
<?php
page_close()
/*
$Log$
Revision 1.1  2001/09/27 13:09:53  honzam
New Cross Server Networking now is working (RSS item exchange)

*/
?>
