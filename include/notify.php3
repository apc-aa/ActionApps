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

#
# utility for notifying people of evets by email
#

if (!defined("NOTIFY_INCLUDED"))
     define ("NOTIFY_INCLUDED",1);
else return;

require $GLOBALS[AA_INC_PATH]."item.php3";
require $GLOBALS[AA_INC_PATH]."view.php3"; 
require $GLOBALS[AA_INC_PATH]."mail.php3"; 

// notify users of an event
function email_notify($slice_id, $event, $item_id, $extra = ""){
  global $db;
  $p_slice_id = q_pack_id($slice_id);

  # expand the body template using the itemview function
  $format['group_by'] = '';
  $format['category_format'] = '';
  $format['compact_bottom'] = '';
  $format['compact_remove'] = '';
  $format['even_row_format'] ='';
  $format['even_odd_differ'] = '0';
  $format['id'] = $slice_id;

  // get alias list from database
  list($fields,) = GetSliceFields($slice_id);
  $aliases = GetAliasesFromFields($fields, $als);

  // select the text templates
  switch ($event){
    case 1: $prefix = 'notify_holding_item'; break;
    case 2: $prefix = 'notify_holding_item_edit'; break;
    case 3: $prefix = 'notify_active_item'; break;
    case 4: $prefix = 'notify_active_item_edit'; break;
  }  

  $SQL = "SELECT ${prefix}_s as s, ${prefix}_b as b from slice where id = '$p_slice_id'";
  $db->query($SQL);
  if( $db->next_record() ){
    $s = $db->f('s');
    $b = $db->f('b');
  } else {
    die ("bad slice_id");
  }

  // determine subject of message
  $format['odd_row_format'] = $s;
  $zids = new zids($item_id);

  $itemview = new itemview( $db, $format, $fields, $aliases, $zids,
                            0, 1, '', "");
  $subject = $itemview->get_output_cached("view");

  // determine body of message
  $format['odd_row_format'] = $b;
  //$item_ids[] = $item_id;   # Ick, this would have put two ids in! 

  $itemview = new itemview( $db, $format, $fields, $aliases, $zids, 
                            0, 1, '', "", 0);
  $body = $itemview->get_output_cached("view");

  // select all the users
  $SQL = "SELECT uid from email_notify where slice_id = '$p_slice_id' AND function = '$event'";
  $db->query($SQL);

/* Jakub replaced by mail_html_text (see mail.php3)
  $headers = "";
  // Comment this out to send text mail
  $headers = "Content-Type: text/html; charset=iso-8859-1\n";
*/

  // loop through the users for the event
  while( $db->next_record() ){
    // unalias the text
   $email = $db->f('uid');
   mail_html_text ($email, $subject, $body, $headers, $LANGUAGE_CHARSETS[get_mgettext_lang()], 0);
   // you cant output here, you are still in the headers section!
   // echo "DONE $email, $s, $body <BR>";
  }

}
?>
