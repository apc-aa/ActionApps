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

# discussion.php3 - discussion utility functions

# discussion images
define ("D_EXPAND_IMG", 0);
define ("D_HLINE_IMG", 1);
define ("D_VLINE_IMG", 2);
define ("D_CORNER_IMG", 3);
define ("D_SPACE_IMG", 4);
define ("D_T_IMG", 5);
define ("D_ITEM", 6);

function PrintImg($src, $width=0, $height=0) {
  $width = $width ? "width=$width" : "";
  $height = $height ? "height=$height" : "";
  return "<img src=".AA_INSTAL_URL."images/$src"." $width $height border='0'></img>";
}

$imgsrc = array(
    D_EXPAND_IMG => PrintImg("d_expand.gif",9,21),
    D_HLINE_IMG => PrintImg("d_hline.gif",9,21),
    D_VLINE_IMG => PrintImg("i.gif",9,21),
    D_CORNER_IMG => PrintImg("l.gif",9,21),
    D_SPACE_IMG => PrintImg("blank.gif",12,21),
    D_T_IMG => PrintImg("t.gif",9,21),
    D_ITEM => "");

function GetImageSrc($img) {
  global $imgsrc;
  return $imgsrc[$img];
}

# get discussion content from database belong to item_id
function GetDiscussionContent($item_id, $ids="", $vid="",$state=true, $order='timeorder', $html_flag=true, $clean_url="") {
  global $db;
  $p_item_id = q_pack_id($item_id);
  $SQL= "SELECT *
           FROM discussion
           WHERE item_id='$p_item_id'";
   $SQL.=" ORDER BY date";
  if ($order == 'reverse timeorder')
    $SQL .=" DESC";

  $db->query($SQL);
  while($db->next_record()) {
    $d_id = unpack_id($db->f(id));
    if (!$ids || $ids["x".$d_id]) {
      $col["d_id............"][0][value] = $d_id;
      $col["d_parent........"][0][value] = $db->f(parent) ? unpack_id($db->f(parent)) : "0";
      $col["d_item_id......."][0][value] = unpack_id($db->f(item_id));
      $col["d_subject......."][0][value] = $db->f(subject);
      $col["d_body.........."][0][value] = $db->f(body);
      $col["d_author........"][0][value] = $db->f(author);
      $col["d_e_mail........"][0][value] = $db->f(e_mail);
      $col["d_url_address..."][0][value] = $db->f(url_address);
      $col["d_url_descript.."][0][value] = $db->f(url_description);
      $col["d_date.........."][0][value] = $db->f(date);
      $col["d_remote_addr..."][0][value] = $db->f(remote_addr);
      $col["d_state........."][0][value] = $db->f(state);
      $col["d_url_fulltext.."][0][value] = $clean_url."&sh_itm=".$item_id."&sel_ids=1&ids[x".$d_id."]=1";
      $col["d_url_reply....."][0][value] = $clean_url."&sh_itm=".$item_id."&add_disc=1&parent_id=".$d_id;
      $col["d_disc_url......"][0][value] = $clean_url ."&sh_itm=".$item_id;

      // set html flag
      if ($html_flag)
        $col["d_body.........."][0][flag] = FLAG_HTML;
      $col["d_checkbox......"][0][flag] = FLAG_HTML;
      $col["d_treeimages...."][0][flag] = FLAG_HTML;

      $col["hide"] = ($db->f(state) == '1' && $state);     //mark hidden comment.
      $d_content[$d_id] = $col;
    }
  }
  return $d_content;
}

// set the right content for a checkbox
function SetCheckboxContent(&$content, $d_id, $cnt) {
  $content[$d_id]["d_checkbox......"][0][value] =
    "<input type=\"checkbox\" name=c_".$cnt." ><input type=hidden name=h_".$cnt." value=x".$d_id."> ";
}

// set the right content for images
function SetImagesContent(&$content, $d_id, &$images, $showimages, &$imgtags) {
  if ($showimages) {
    while (list(, $img) = each($images)) {
      $imgs.= $imgtags[$img];
    }
  }
  else {
    $imgs = PrintImg("blank.gif",count($images)*15, 21);
  }
  $content[$d_id]["d_treeimages...."][0][value] = $imgs;
}

function GetButtons($empty, $script_loc) {
  if (!$empty) {
    $out.= "<input type=button name=sel_ids value=\"" .L_D_SHOW_SELECTED. "\" onClick=showSelectedComments() >
            <input type=button name=all_ids value=\"" .L_D_SHOW_ALL ."\" onClick=document.location=\"".$script_loc."&all_ids=1\" >";
  }
    $out.= " <input type=button name=add_disc value=\"". L_D_ADD_NEW. "\" onClick=document.location=\"".$script_loc."&add_disc=1\" >";
  return $out;
}

function GetAlias($fce, $param, $help) {
  return  array( "fce"   =>   $fce,
                 "param" =>   $param,
                 "hlp"   =>   $help );
}

function GetDiscussionAliases() {
  #  Standard aliases
  $aliases["_#SUBJECT#"] = GetAlias("f_h", "d_subject.......", L_D_SUBJECT_ALIAS);
  $aliases["_#BODY####"] = GetAlias("f_t", "d_body..........", L_D_BODY_ALIAS);
  $aliases["_#AUTHOR##"] = GetAlias("f_h", "d_author........", L_D_AUTHOR_ALIAS);
  $aliases["_#EMAIL###"] = GetAlias("f_h", "d_e_mail........", L_D_EMAIL_ALIAS);
  $aliases["_#WWW_URL#"] = GetAlias("f_h", "d_url_address...", L_D_WWWURL_ALIAS);
  $aliases["_#WWW_DESC"] = GetAlias("f_h", "d_url_descript..", L_D_WWWDES_ALIAS);
  $aliases["_#DATE####"] = GetAlias("f_d:d M  H:i",     "d_date..........", L_D_DATE_ALIAS);
  $aliases["_#IP_ADDR#"] = GetAlias("f_h", "d_remote_addr...", L_D_REMOTE_ADDR_ALIAS);
  $aliases["_#CHECKBOX"] = GetAlias("f_h", "d_checkbox......", L_D_CHECKBOX_ALIAS);
  $aliases["_#TREEIMGS"] = GetAlias("f_h", "d_treeimages....", L_D_TREEIMGS_ALIAS);
  $aliases["_#ITEM_ID#"] = GetAlias("f_h", "d_item_id.......", L_D_ITEM_ID_ALIAS);
  $aliases["_#DISC_ID#"] = GetAlias("f_h", "d_id............", L_D_ID_ALIAS);
  $aliases["_#URL_BODY"] = GetAlias("f_h", "d_url_fulltext..", L_D_URLBODY_ALIAS);
  $aliases["_#URLREPLY"] = GetAlias("f_h", "d_url_reply.....", L_D_URLREPLY_ALIAS);
  $aliases["_#DISC_URL"] = GetAlias("f_h", "d_disc_url......", L_D_URL);
  $aliases["_#BUTTONS#"] = GetAlias("f_h", "d_buttons.......", L_D_BUTTONS);
//  $aliases["_#SHOW_ALL"] = GetAlias("f_h", "d_show_all......", L_D_SHOW_ALL_ALIAS);
//  $aliases["_#SHOW_SEL"] = GetAlias("f_h", "d_show_sel......", L_D_SHOW_SEL_ALIAS);
//  $aliases["_#ADD_NEW#"] = GetAlias("f_h", "d_add_new.......", L_D_ADD_NEW_ALIAS);

  return $aliases;
}

function GetDiscussionFormat(&$view_info) {
  global $VIEW_TYPES_INFO;

  $format['d_name'] = $view_info['name'];
  $format['d_top'] = $view_info['before'];
  $format['d_bottom'] = $view_info['after'];
  $format['d_fulltext'] = $view_info['even'];
  $format['d_compact'] = $view_info['odd'];
  $format['d_showimages'] = $view_info['even_odd_differ'];
  $format['d_order'] = $VIEW_TYPES_INFO['discus']['modification'][$view_info['modification']];
  $format['slice_id'] = $view_info['slice_id'];
  $format['d_form'] = $view_info['remove_string'];
  $format['images'] = array(
                         D_VLINE_IMG => $view_info['img1'],
                         D_CORNER_IMG => $view_info['img2'],
                         D_T_IMG => $view_info['img3'],
                         D_SPACE_IMG => $view_info['img4']
                           );
   return $format;
}

// Create discussion tree from d_content.
function GetDiscussionTree(&$d_content) {
  if (!$d_content)
    return;
  while (list($d_id, $val) = each($d_content)) {
    if ($val["hide"] == true)                   // if hidden => skip
      continue;
    $id = $d_id;                                // searching approved parent disc. comment for $d_id
    do {
      $id = $d_content[$id]["d_parent........"][0][value];
      if ($id == "0")
        break;
    } while ($d_content[$id]["hide"] == true);

    $d_tree[$id][$d_id] = true;
  }
  return $d_tree;
}


// create array of images
function GetDiscussionThread(&$tree, $d_id, $depth, &$outcome, $images="") {
     if ($d_id != "0") {
      for ($i=1; $i<$depth-1; $i++)
        $outcome[$d_id][] = $images[$i];
        if ($depth>1) {
          $outcome[$d_id][] = $images[$depth];
//          $outcome[$d_id][] =  D_SPACE_IMG;
        }
//        $outcome[$d_id][] = $tree[$d_id] ? D_EXPAND_IMG : D_HLINE_IMG;
        $outcome[$d_id][] = D_ITEM;
    }
    if (!($nodes = $tree[$d_id]))
      return;

    while (list($dest_id,) = each($nodes)) {
      if (current($nodes)) {
        $images[$depth] =  D_VLINE_IMG;
        $images[$depth+1] = D_T_IMG;
      } else {
        $images[$depth] =  D_SPACE_IMG;
        $images[$depth+1] = D_CORNER_IMG;
      }
     GetDiscussionThread($tree, $dest_id, $depth+1, $outcome, $images);
    }
  }

// delete subtree of d_id - not used yet
function DeleteTree(&$tree, $d_id) {
  global $db;

  $p_d_id = q_pack_id($d_id);
  $db->query("DELETE FROM discussion WHERE id='$p_d_id'");
  if (!($nodes = $tree[$d_id]))
    return;
  while (list($dest_id,) = each($nodes))
    DeleteTree($tree,$dest_id);
}

// get parent of node $d_id in tree
function GetParent(&$tree, $d_id) {
  if (!$tree)
    return;
  reset($tree);
  while (list($source_id, ) = each($tree)) {
    while (list($dest_id, ) = each($tree[$source_id])) {
      if ($dest_id == $d_id)
        return $source_id;
    }
  }
}

// delete one comment
function DeleteNode(&$tree, &$d_content, $d_id) {
  global $db;
  $db->query("DELETE FROM discussion WHERE id='".q_pack_id($d_id)."'");

  if (!$tree[$d_id])
    return;
  $parent = $d_content[$d_id]["d_parent........"][0][value];

  while (list ($child, ) = each($tree[$d_id])) {
    $db->query("UPDATE discussion SET parent='".($parent == "0" ? "" : q_pack_id($parent))."' WHERE id='".q_pack_id($child)."'");
  }
}

// Update a count of discussion comments in the view table (called after adding|deleting|hiding|approving comment)
function updateDiscussionCount($item_id) {
  global $db;

  $all = $hide = 0;
  $p_item_id = q_pack_id($item_id);
  $SQL= "SELECT * FROM discussion WHERE item_id='$p_item_id'";
  $db->query($SQL);
  while($db->next_record()) {
    $all++;
    if ($db->f(state) == '1')    // hidden comment
      $hide++;
  }

  $SQL= "UPDATE item SET disc_count='$all', disc_app='". ($all-$hide) ."' WHERE id='$p_item_id'";
  $db->query($SQL);
}
/*
$Log$
Revision 1.2  2001/12/12 18:39:44  honzam
Better handling newlines (<BR>)

Revision 1.1  2001/09/27 13:15:47  honzam
New discussion support

*/
?>
