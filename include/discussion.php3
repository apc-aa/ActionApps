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

// discussion.php3 - discussion utility functions

require_once $GLOBALS['AA_INC_PATH']."mail.php3";

// discussion images
define ("D_EXPAND_IMG", 0);
define ("D_HLINE_IMG", 1);
define ("D_VLINE_IMG", 2);
define ("D_CORNER_IMG", 3);
define ("D_SPACE_IMG", 4);
define ("D_T_IMG", 5);
define ("D_ITEM", 6);

function PrintImg($src, $width=0, $height=0, $path='rel') {
  $width = $width ? "width=$width" : "";
  $height = $height ? "height=$height" : "";
  $img = (($path=='rel') ? "../images/$src" : $GLOBALS[AA_INSTAL_PATH]."images/$src");
  return "<img src=\"$img\" $width $height border='0'></img>";
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

// get discussion content from database belong to item_id
function GetDiscussionContent($item_id, $ids="", $vid="",$state=true, $order='timeorder', $html_flag=true, $clean_url="") {
    if ( !$item_id ) {
        return false;
    }
    $p_item_id = q_pack_id($item_id);
    $SQL = "SELECT * FROM discussion WHERE item_id='$p_item_id' ORDER BY date";
    if ($order == 'reverse timeorder') {
        $SQL .=" DESC";
    }
    return GetDiscussionContentSQL($SQL, $item_id, $ids, $vid, $state, $html_flag, $clean_url);
}

function setDiscUrls(&$col, $clean_url, $item_id, $d_id=null) {
    $tmp_disc_url = con_url($clean_url,"nocache=invalidate&sh_itm=$item_id");
    $col["d_disc_url......"][0]['value'] = $tmp_disc_url."#disc";
    // in urls we do not need to replace & by &amp;
    $col["d_disc_url......"][0]['flag']  = FLAG_HTML;
    if ($d_id) {
        $col["d_url_fulltext.."][0]['value'] = $tmp_disc_url."&sel_ids=1&ids[x".$d_id."]=1#disc";
        $col["d_url_reply....."][0]['value'] = $tmp_disc_url."&add_disc=1&parent_id=".$d_id."#disc";
    }
}

function GetDiscussionContentSQL($SQL, $item_id, $ids, $vid, $state, $html_flag, $clean_url) {
    global $db;
    $db->tquery($SQL);
    while ($db->next_record()) {
        $d_id = unpack_id128($db->f('id'));
        if (!$ids || $ids["x".$d_id]) {
            $col["d_id............"][0]['value'] = $d_id;
            $col["d_parent........"][0]['value'] = $db->f('parent') ? unpack_id($db->f('parent')) : "0";
            $col["d_item_id......."][0]['value'] = unpack_id128($db->f('item_id'));
            $col["d_subject......."][0]['value'] = $db->f('subject');
            $col["d_body.........."][0]['value'] = $db->f('body');
            $col["d_author........"][0]['value'] = $db->f('author');
            $col["d_e_mail........"][0]['value'] = $db->f('e_mail');
            $col["d_url_address..."][0]['value'] = $db->f('url_address');
            $col["d_url_descript.."][0]['value'] = $db->f('url_description');
            $col["d_date.........."][0]['value'] = $db->f('date');
            $col["d_remote_addr..."][0]['value'] = $db->f('remote_addr');
            $col["d_state........."][0]['value'] = $db->f('state');
            setDiscUrls($col, $clean_url,unpack_id128($db->f('item_id')),$d_id);

            // set html flag
            if ($html_flag) {
                $col["d_body.........."][0]['flag'] = FLAG_HTML;
            }
            $col["d_checkbox......"][0]['flag'] = FLAG_HTML;
            $col["d_treeimages...."][0]['flag'] = FLAG_HTML;

            $col["hide"] = ($db->f('state') == '1' && $state);     //mark hidden comment.
            $d_content[$d_id] = $col;
        }
    }
    return $d_content;
}

// set the right content for a checkbox
function SetCheckboxContent(&$content, $d_id, $cnt) {
  $content[$d_id]["d_checkbox......"][0]['value'] =
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
    $imgs = PrintImg("blank.gif",count($images)*15, 21, 'abs');
  }
  $content[$d_id]["d_treeimages...."][0]['value'] = $imgs;
}

function GetButtons($empty, $script_loc) {
  if (!$empty) {
    $out.= "<input type=button name=sel_ids value=\"" ._m("Show selected"). "\" onClick=showSelectedComments() class=\"discbuttons\">
            <input type=button name=all_ids value=\"" ._m("Show all") ."\" onClick=document.location=\"".con_url($script_loc,"nocache=invalidate&all_ids=1")."\" class=\"discbuttons\">";
  }
    $out.= " <input type=button name=add_disc value=\"". _m("Add new"). "\" onClick=document.location=\"".con_url($script_loc,"nocache=invalidate&add_disc=1")."\" class=\"discbuttons\">";
  return $out;
}

function GetDiscussionAliases() {
  //  Standard aliases
  $aliases["_#SUBJECT_"] = GetAliasDef("f_h",          "d_subject.......", _m("Alias for subject of the discussion comment"));
  $aliases["_#BODY###_"] = GetAliasDef("f_t",          "d_body..........", _m("Alias for text of the discussion comment"));
  $aliases["_#AUTHOR#_"] = GetAliasDef("f_h",          "d_author........", _m("Alias for written by"));
  $aliases["_#EMAIL##_"] = GetAliasDef("f_h",          "d_e_mail........", _m("Alias for author's e-mail"));
  $aliases["_#WWW_URL_"] = GetAliasDef("f_h",          "d_url_address...", _m("Alias for url address of author's www site"));
  $aliases["_#WWW_DESC"] = GetAliasDef("f_h",          "d_url_descript..", _m("Alias for description of author's www site"));
  $aliases["_#DATE###_"] = GetAliasDef("f_d:d M Y H:i","d_date..........", _m("Alias for publish date"));
  $aliases["_#IP_ADDR_"] = GetAliasDef("f_h",          "d_remote_addr...", _m("Alias for IP address of author's computer"));
  $aliases["_#CHECKBOX"] = GetAliasDef("f_h",          "d_checkbox......", _m("Alias for checkbox used for choosing discussion comment"));
  $aliases["_#TREEIMGS"] = GetAliasDef("f_h",          "d_treeimages....", _m("Alias for images"));
  $aliases["_#DITEM_ID"] = GetAliasDef("f_h",          "d_item_id.......", _m("Alias for comment ID<br>\n                             <i>Usage: </i>in form code<br>\n                             <i>Example: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">"));
  $aliases["_#ITEM_ID_"] = GetAliasDef("f_h",          "d_item_id.......", _m("Alias for comment ID<br>\n                             <i>Usage: </i>in form code<br>\n                             <i>Example: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">"));
  $aliases["_#DISC_ID_"] = GetAliasDef("f_h",          "d_id............", _m("Alias for item ID<br>\n                             <i>Usage: </i>in form code<br>\n                             <i>Example: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">"));
  $aliases["_#URL_BODY"] = GetAliasDef("f_h",          "d_url_fulltext..", _m("Alias for link to text of the discussion comment<br>\n                             <i>Usage: </i>in HTML code for index view of the comment<br>\n                             <i>Example: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>"));
  $aliases["_#URLREPLY"] = GetAliasDef("f_h",          "d_url_reply.....", _m("Alias for link to a form<br>\n                             <i>Usage: </i>in HTML code for fulltext view of the comment<br>\n                             <i>Example: </i>&lt;a href=_#URLREPLY&gt;Reply&lt;/a&gt;"));
  $aliases["_#DISC_URL"] = GetAliasDef("f_h",          "d_disc_url......", _m("Alias for link to discussion<br>\n                             <i>Usage: </i>in form code<br>\n                             <i>Example: </i>&lt;input type=hidden name=url value=\"_#DISC_URL\">"));
  $aliases["_#BUTTONS_"] = GetAliasDef("f_h",          "d_buttons.......", _m("Alias for buttons Show all, Show selected, Add new<br>\n                             <i>Usage: </i> in the Bottom HTML code"));

  return $aliases;
}

function GetDiscussionFormat(&$view_info) {
  $VIEW_TYPES_INFO = getViewTypesInfo();

  $format['d_name'] = $view_info['name'];
  $format['d_top'] = $view_info['before'];
  $format['d_bottom'] = $view_info['after'];
  $format['d_fulltext'] = $view_info['even'];
  $format['d_compact'] = $view_info['odd'];
  $format['d_showimages'] = $view_info['even_odd_differ'];
  $format['d_order'] = $VIEW_TYPES_INFO['discus']['modification'][$view_info['modification']];
  $format['slice_id'] = $view_info['slice_id'];
  $format['d_form'] = $view_info['remove_string'];
  $format['d_spacer']   = ( $view_info['aditional']  ? $view_info['aditional'] :
                                                       '<img src="'.$GLOBALS[AA_INSTAL_PATH].'images/blank.gif" width=20 height=1 border="0">');
  $format['d_sel_butt'] = ( $view_info['aditional2'] ? $view_info['aditional2'] :
                                                       '<input type=button name=sel_ids value="' ._m("Show selected"). '" onClick=showSelectedComments() class="discbuttons">');
  $format['d_all_butt'] = ( $view_info['aditional3'] ? $view_info['aditional3'] :
                                                       '<input type=button name=all_ids value="' ._m("Show all"). '" onClick=showAllComments() class="discbuttons">');
  $format['d_add_butt'] = ( $view_info['aditional4'] ? $view_info['aditional4'] :
                                                       '<input type=button name=add_disc value="' ._m("Add new"). '" onClick=showAddComments() class="discbuttons">');
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
      $id = $d_content[$id]["d_parent........"][0]['value'];
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
  $db->tquery("DELETE FROM discussion WHERE id='$p_d_id'");
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
  $db->tquery("DELETE FROM discussion WHERE id='".q_pack_id($d_id)."'");

  if (!$tree[$d_id])
    return;
  $parent = $d_content[$d_id]["d_parent........"][0]['value'];

  while (list ($child, ) = each($tree[$d_id])) {
    $db->tquery("UPDATE discussion SET parent='".($parent == "0" ? "" : q_pack_id($parent))."' WHERE id='".q_pack_id($child)."'");
  }
}

// Update a count of discussion comments in the view table (called after adding|deleting|hiding|approving comment)
function updateDiscussionCount($item_id) {
  global $db;

  $all = $hide = 0;
  $p_item_id = q_pack_id($item_id);
  $SQL= "SELECT * FROM discussion WHERE item_id='$p_item_id'";
  $db->tquery($SQL);
  while ($db->next_record()) {
    $all++;
    if ($db->f(state) == '1')    // hidden comment
      $hide++;
  }

  $SQL= "UPDATE item SET disc_count='$all', disc_app='". ($all-$hide) ."' WHERE id='$p_item_id'";
  $db->tquery($SQL);
}

// -----------------------------------------------------------------------------------------

/** used just for help display in admin/se_view.php3 */
function GetDiscussion2MailAliases() {
  $aliases["_#ITEMPAR3"] = GetAlias("", "", _m("3rd parameter filled in DiscussionMailList field"));
  for ($i = 4; $i < 10; $i ++)
      $aliases["_#ITEMPAR$i"] = GetAlias("", "", _m("%1th parameter filled in DiscussionMailList field", array ($i)));
  return $aliases;
}

// -----------------------------------------------------------------------------------------
/* This function sends new discussion items to one mail address
   if a field with name DiscussionMailList
   exists and is filled with these parameters separated by ":" (use "#:" instead of verbatim ":")

   view_id:mail_address:param3:param4:...
*/

function send2mailList ($d_item_id, $new_id) {
    global $db;
    $db->tquery("SELECT content.text FROM
                 content INNER JOIN item ON item.id = content.item_id INNER JOIN
                 field ON content.field_id = field.id
                 AND field.slice_id = item.slice_id
                 WHERE item.id='".q_pack_id($d_item_id)."'
                 AND field.name = 'DiscussionMailList'");
    if ($db->next_record()) {
        $item_params = split_escaped (":", $db->f("text"), "#:");
        list ($vid, $maillist) = $item_params;
      // Don't do this if there is a field, but no vid in it
      if ($vid) {
        // get discussion item content
        $columns = GetDiscussionContentSQL (
            "SELECT * FROM discussion WHERE id = '".q_pack_id($new_id)."'",
            $d_item_id, "", $vid, true, $html, "");
        $columns = reset($columns);  // get first element

        // get aliases
        $aliases = GetDiscussionAliases();
        for ($i=2; $i < count($item_params); $i++) {
            FillFakeAlias($columns, $aliases, "_#ITEMPAR".($i+1), $item_params[$i]);
        }

        $CurItem = new item($columns, $aliases);

        // newer version based on email templates
        if ( $vid{0} == 't' ) {   // email template
            $mail_id = substr($vid,1);
            send_mail_from_table_inner ($mail_id, $maillist, $CurItem);
            return;
        }
        $db->tquery("SELECT * FROM view WHERE id=$vid");
        if ($db->next_record()) {
            $view_info = $db->Record;

            $html = $view_info[flag] & DISCUS_HTML_FORMAT;

            // older and deprecated version with discussion view
            $mail_parts = array (
                "from" => "aditional",
                "reply_to" => "aditional2",
                "errors_to" => "aditional3",
                "sender" => "aditional4",
                "subject" => "aditional5",
                "body" => "even");

            reset ($mail_parts);
            $mail = "";
            while (list($part, $field) = each ($mail_parts)) {
                $s = $view_info [$field];
                for ($i=2; $i < 9; $i ++) {
                    $s = str_replace ("_#ITEMPAR".($i+1), $item_params [$i], $s);
                }
                $CurItem->setformat($s);
                $mail [$part] = $CurItem->get_item();
            }

            $mail = new HtmlMail;
            $mail->setSubject($mail["subject"]);
            $mail->setHtml($mail["body"], html2text ($mail["body"]));
            if ($mail["from"])      $mail->setHeader("From", $mail["from"]);
            if ($mail["reply_to"])  $mail->setHeader("Reply-To", $mail["reply_to"]);
            if ($mail["errors_to"]) $mail->setHeader("Errors-To", $mail["errors_to"]);
            if ($mail["sender"])    $mail->setHeader("Sender", $mail["sender"]);

            $db->tquery("SELECT lang_file FROM slice INNER JOIN item ON item.slice_id = slice.id
                         WHERE item.id='".q_pack_id($d_item_id)."'");
            $db->next_record();
            $mail->setCharset($GLOBALS["LANGUAGE_CHARSETS"][substr($db->f("lang_file"),0,2)]);
            $mail->send(array($maillist));
        } //view found
      } // vid present
    } // DiscussionMailList Field present
}

?>
