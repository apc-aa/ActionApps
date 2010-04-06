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
  Script move.php3 is database trnansformation script, which copies whole slice
  from ActionApps v1.2- to the new database structure, which is used for
  ActionApps v1.5+.

  How to transform the old slice to the new one?

1) Go on Admin - Slice structure Import
2) Copy the content of the /misc/oldDb2Db/oldSliceTemplate.txt file to the
   import area and press 'Send the slices structure' button. (This slice is then
   used as template for all imported slices. It makes no sence to repeat steps
   1) and 2) for importing second slice !!!
3) Modify next few lines in this script and fill the
   - OLD_DB credentials to be able to connect to source database
   - owner id
   - old (source) slice id
4) Remove the line just before this coment
5) Run thiws script (you have to log as SuperAdmin, then)
6) Done

   What is Done:
- New slice in new database is created based on oldSliceTemplate (the slice id
  is the same as in old database)
- old format strings are copied to the new slice
- all fields in new slice are set is in old slice (defaults, visibility,
  required, ...)
- categories are copied and added to constants as it is obvious in AA v1.5+
- all fields of all items in source slice are copied to the new database
- relation table is updated and feeding relations is stored there

   Limitations:
- permissions for slice is not copied in case you are using SQL version of
  permission system (in fact, the oermission is not copied in LDAP version
  too, but it is obvious, that old AA and new AA share the same LDAP tree, so
  the copy of permissions is not needed)
- field names are allways the same and it comes from en_news_lang.php3
  (see Admin - Fields for change the names)
- Show on URL (redirect) field is copied to the new database, but alias
  (_#HDLN_URL, ...) do not count with this field. You should set the new alias
  in order you get the same result as in old database
- the defaut expiry date can be set different in old and new database in some
  cases (see Admin - Fields - Expiry date Edit - Default)
- automatical search form is not implemented in AA v1.5+ - all search settings
  are removed

   After import you have to change the include lines in the pages in order the
   new slice is called - for example change old line in *.shtml file:

<!--#include virtual="/aa12/slice.php3?slice_id=4e6577735f454e5f746d706c2e2e2e2e"-->

   to point to new AAv2.0 installation directory:

<!--#include virtual="/aa20/slice.php3?slice_id=4e6577735f454e5f746d706c2e2e2e2e"-->

   The slice id is the same. After that, you shoud review all public pages to
   make you sure, it works as expected. I do not expect many problems. All
   features of AAv1.2- as possible to implement in AAv2.0. The biggest problem
   should be the search form, but it is quite easy to create it manually.

*/

// Change this variable to true, if You realy want to move the slice. If You
// left the $fire variable as false, the commands are just written to screen
// and they are not executed - database is not changed
// $fire = true;
$fire = false;

// set credentials for access to old (source = v <=1.2) database
define("OLD_DB_HOST", "localhost");
define("OLD_DB_NAME", "aadb-old");
define("OLD_DB_USER", "aadbuser-old");
define("OLD_DB_PASSWORD", "password_for_old_database");

// Do you use the extended item table structure in source database (if you have
// for example the 'con_name' field in item table, you are using extended format.)
// (there should be some more chnges in move_util.php3 for extended item table on)
define("EXTENDED_ITEM_TABLE", "0");

// Econnect's owner ID see source code for Admin - Slice page and look for
// Owner field (in new AA)
$owner = "56749ab7829927763672922663e7ab82";

// If you want to insert new owner, uncoment and fill next three rows
// $new_owner_email = "greenpeace@ecn.cz";
// $new_owner = "Greenpeace CZ";
// unset($owner);

// unpacked slice id of moved slice
// (the slice id remains the same in new database)
$old_slice_id = '34757584373657272565d624e1acd811';

$permit_anonymous_post = 0;
$permit_offline_fill = 0;

$lang_file = "en_news_lang.php3";


// internal variables
// $template_id = "4febb632bcd13ea130999b3f496923b2";    // CZ template
$template_id = "96f50946e092fd45ed90af3de572bd4e";  // EN template
$template = 0;

require_once "../../include/config.php3";
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."itemfunc.php3";
require_once AA_INC_PATH."notify.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."date.php3";
require_once AA_INC_PATH."feeding.php3";
require_once AA_INC_PATH."mgettext.php3";
require_once AA_BASE_PATH."misc/oldDb2Db/move_util.php3";

class OLD_DB_AA extends DB_Sql {
  var $Host     = OLD_DB_HOST;
  var $Database = OLD_DB_NAME;
  var $User     = OLD_DB_USER;
  var $Password = OLD_DB_PASSWORD;
  function halt($msg) {
    printf("</td></table><b>Database error:</b> %s<br>\n", $msg);
    printf("<b>MySQL Error</b>: %s (%s)<br>\n",
      $this->Errno, $this->Error);
    echo("Please contact ". ERROR_REPORTING_EMAIL ." and report the ");
    printf("exact error message.<br>\n");    die("Session halted.");
  }
}

$odb  = new OLD_DB_AA;
$db  = new DB_AA;
$db2  = new DB_AA;

if ($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

$oldinfo = GetOldSliceInfo($old_slice_id); // get fields of slices table from old database
$slice_id = $old_slice_id;        // the same as in source slice
$p_slice_id = q_pack_id($slice_id);

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$itemvarset = new Cvarset();
set_time_limit(160);

do {
  if ( !$owner ) {  // insert new owner
    ValidateInput("new_owner", _m("New Owner"), $new_owner, $err, true, "text");
    ValidateInput("new_owner_email", _m("New Owner's E-mail"), $new_owner_email, $err, true, "email");

    if ( count($err) > 1)
      break;

    $owner = new_id();
    $varset->set("id", $owner, "unpacked");
    $varset->set("name", $new_owner, "text");
    $varset->set("email", $new_owner_email, "text");

      // create new owner
    $SQL = "INSERT INTO slice_owner " . $varset->makeINSERT();
    huhu( $SQL );
    if ( $fire ) {
      if ( !$db->query($SQL)) {
        $err["DB"] .= MsgErr("Can't add slice");
        break;
      }
    }

    $varset->clear();
  }

  // copy slice setting from old database
  $name = $oldinfo["short_name"];
  $slice_url = $oldinfo["slice_url"];
  $d_listlen = $oldinfo["d_listlen"];
  $deleted = $oldinfo["deleted"];

  ValidateInput("name", _m("Title"), $name, $err, true, "text");
  ValidateInput("owner", _m("Owner"), $owner, $err, false, "id");
  ValidateInput("slice_url", _m("URL of .shtml page (often leave blank)"), $slice_url, $err, false, "url");
  ValidateInput("d_listlen", _m("Listing length"), $d_listlen, $err, true, "number");
  ValidateInput("permit_anonymous_post", _m("Allow anonymous posting of items"), $permit_anonymous_post, $err, false, "number");
  ValidateInput("permit_offline_fill", _m("Allow off-line item filling"), $permit_offline_fill, $err, false, "number");
  ValidateInput("lang_file", _m("Used Language File"), $lang_file, $err, true, "text");

  if ( count($err) > 1)
    break;

  $deleted  = ( $deleted  ? 1 : 0 );

    // get template data
  $varset->addArray( $SLICE_FIELDS_TEXT, $SLICE_FIELDS_NUM );
  $SQL = "SELECT * FROM slice WHERE id='". q_pack_id($template_id) ."'";
  huhu( $SQL );
  $db->query($SQL);
  if ( !$db->next_record() ) {
    $err["DB"] = MsgErr("Bad template id");
    break;
  }
  $varset->setFromArray($db->Record);

  $varset->set("id", $slice_id, "unpacked");
  $varset->set("created_by", $oldinfo["created_by"], "text");
  $varset->set("created_at", strtotime($oldinfo["created_at"]), "text");
  $varset->set("name", $name, "quoted");
  $varset->set("owner", $owner, "unpacked");
  $varset->set("slice_url", $slice_url, "quoted");
  $varset->set("d_listlen", $d_listlen, "number");
  $varset->set("deleted", $deleted, "number");
  $varset->set("template", $template, "number");
  $varset->set("permit_anonymous_post", $permit_anonymous_post, "number");
  $varset->set("permit_offline_fill", $permit_offline_fill, "number");
  $varset->set("lang_file", $lang_file, "quoted");

     // create new slice
  $SQL = "INSERT INTO slice" . $varset->makeINSERT();
  huhu( $SQL );
  if ( $fire ) {
    if ( !$db->query( $SQL )) {
      $err["DB"] .= MsgErr("Can't add slice");
      break;
    }
  }

  echo "<br><b>Destination slice created</b>";

     // copy fields from template slice ('Template: old ActionApps 1.2')
  $SQL = "SELECT * FROM field WHERE slice_id='". q_pack_id($template_id) ."'";
  huhu( $SQL );
  $db->query($SQL);
  while ( $db->next_record() ) {
    $varset->clear();
    $varset->addArray( $FIELD_FIELDS_TEXT, $FIELD_FIELDS_NUM );
    $varset->setFromArray($db->Record);
    $varset->set("slice_id", $slice_id, "unpacked" );
    $SQL = "INSERT INTO field " . $varset->makeINSERT();
    huhu( $SQL );
    if ( $fire ) {
      if ( !$db2->query($SQL)) {
        $err["DB"] .= MsgErr("Can't copy fields");
        break;
      }
    }
  }

  echo "<br><b>Fields from  template copied</b>";

    // modify the fields setting according to source slice setting

  $show = UnpackFieldsToArray($oldinfo["edit_fields"], $show_needed_fields);
  $needed = UnpackFieldsToArray($oldinfo["needed_fields"], $show_needed_fields);

  $show['headline'] = true;    // this is default in AA1.2
  $needed['headline'] = true;

//echo "------------------------------------------<br>";
//print_r($show);
//print_r($needed);

  reset($itemedit_fields);
  while ( list($oldfld, $arr) = each($itemedit_fields)) {
    if ( $arr[2] )  // field is stored in new new database
      UpdateNewField( $arr[2], $oldinfo[$arr[1]], $show[$oldfld], $needed[$oldfld] );
  }

  echo "<br><b>Fields setting modified according to source slice setting</b>";

    // set HTML coding for fulltext

  $SQL = "UPDATE field set html_show=". ($show["html_formatted"] ? 1 : 0) .",
                           html_default=". ($oldinfo["d_html_formatted"] ? 1 : 0) . "
           WHERE id='full_text.......'
             AND slice_id = '$p_slice_id'";
  huhu( $SQL );
  if ( $fire ) {
    if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
      $err["DB"] = MsgErr("Can't change slice");
      break;
    }
  }

  echo "<br><b>HTML coding for fulltext set</b>";

    // set format strings

  $varset->clear();
  $varset->set("fulltext_format", $oldinfo["fulltext_format"], "text" );
  $varset->set("odd_row_format", $oldinfo["odd_row_format"], "text" );
  $varset->set("even_row_format",  $oldinfo["even_row_format"], "text");
  $varset->set("even_odd_differ", $oldinfo["even_odd_differ"], "number");
  $varset->set("compact_top", $oldinfo["compact_top"], "text");
  $varset->set("compact_bottom", $oldinfo["compact_bottom"], "text");
  $varset->set("category_sort",  $oldinfo["category_sort"], "number");
  $varset->set("category_format", $oldinfo["category_format"], "text");
  $varset->set("compact_remove",  $oldinfo["compact_remove"], "text");
  $varset->set("fulltext_remove", $oldinfo["fulltext_remove"], "text");
  $varset->set("export_to_all",  $oldinfo["export_to_all"], "number");
  $varset->set("type", $oldinfo["type"], "text");

  $SQL = "UPDATE slice SET ". $varset->makeUPDATE() .
         " WHERE id='$p_slice_id'";
  huhu( $SQL );
  if ( $fire ) {
    if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
      $err["DB"] = MsgErr("Can't change slice");
      break;
    }
  }

  echo "<br><b>Format strings in slice table set</b>";

    // copy items

  list($fields,) = GetSliceFields($slice_id);

  $SQL = "SELECT items.*, fulltexts.full_text, categories.name as category
            FROM items, fulltexts
            LEFT JOIN categories ON categories.id=items.category_id
           WHERE items.slice_id = '$p_slice_id' AND fulltexts.ft_id=items.master_id";
  huhu( $SQL );
  if (!$odb->query($SQL)) {  // not necessary - we have set the halt_on_error
    $err["DB"] = MsgErr("Can't select");
    break;
  }
  while ( $odb->next_record() ) {
    $content4id = PrepareContent4id($odb->Record);

    if ( $fire ) {
      $navrat = StoreItem( unpack_id($odb->f('id')), $slice_id, $content4id, $fields, true, false, false );
    }                                        // insert, do not invalidatecache, do not feed

    echo "<br>Item '". $odb->f('headline') ."' stored";

    // update relation table - stores where is what fed
    if ( $odb->f('id') != $odb->f('master_id') ) {
      $SQL = "INSERT INTO relation ( destination_id, source_id,   flag )
                   VALUES ( '$p_id', '$p_item_id', '". REL_FLAG_FEED ."' )";
      huhu( $SQL );
      if ( $fire ) {
        if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
          $err["DB"] = MsgErr("Can't change slice");
          break;
        }
      }
      echo " - <b>fed</b>";
    }
  }

  echo "<br><b>All slice items copied</b>";

    // create categories

  $categories = GetOldCategories();
  if ( isset($categories) AND is_array($categories) ) {
    $group_id = substr($oldinfo["short_name"], 0, 15) . '#';   // create new constant group
    $SQL = "INSERT INTO constant SET id='". q_pack_id(new_id()) ."',
                                     group_id='lt_groupNames',
                                     name='$group_id',
                                     value='$group_id',
                                     class='',
                                     pri='100'";
    huhu( $SQL );
    if ( $fire ) {
      if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
        $err["DB"] = MsgErr("Can't change slice");
        break;
      }
    }

    echo "<br><b>Category group created</b>";

    reset($categories);
    $pri = 0;
    while ( list($key,$nam) = each($categories) ) {
      if ( $nam == "" )   // remove this constant
        continue;
      $pri += 100;
      $varset->clear();
      $varset->set("id", $key, "unpacked" );  // remove beginning 'x'
      $varset->set("group_id", $group_id, "quoted" );
      $varset->set("name",  $nam, "quoted");
      $varset->set("value", $nam, "quoted");
      $varset->set("pri", $pri, "number");
      $varset->set("class", "", "quoted");

      $SQL = "INSERT INTO constant " . $varset->makeINSERT();
      huhu( $SQL );
      if ( $fire ) {
        if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
          $err["DB"] .= MsgErr("Can't copy constant");
          break;
        }
      }
      echo "<br><b>Category '$nam' inserted</b>";
    }

    UpdateNewField( 'category........', $categories[unpack_id($oldinfo["d_category_id"])], $show['category_id'], $needed['category_id'] );

    $SQL = "UPDATE field set input_show_func='sel:$group_id'
             WHERE id='category........' AND slice_id = '$p_slice_id'";
    huhu( $SQL );
    if ( $fire ) {
      if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
        $err["DB"] .= MsgErr("Can't copy constant");
        break;
      }
    }
    echo "<br><b>Constants asociated with category field</b>";

    echo "<br><b>Default settings for category field updatated</b>";
  }

  $GLOBALS[pagecache]->invalidate();  // invalidate old cached values - all
}while(false);
if ( count($err) > 1 ) {
  page_close();                                // to save session variables
  ExitScript($err);
}
else
  echo "<br><b>Slice succesfully copied</b>";

/*
$Log: move.php3,v $
Revision 1.10  2006/06/14 13:30:42  honzam
fixed security problem require (see http://secunia.com/advisories/20299/). Requires no longer use variables

Revision 1.9  2005/04/29 11:20:49  honzam
fixed include paths to be absolute and not relative. Relative paths in require_once makes some problem on AA install on WinXP. thanks belongs to Omar Martinez @ Colnodo

Revision 1.8  2005/04/25 11:46:21  honzam
a bit more beauty code - some coding standards setting applied

Revision 1.7  2003/03/11 23:43:26  mitraearth
globalized pagecache

Revision 1.6  2003/02/05 14:56:15  jakubadamek
changing require to require_once, deleting the "if (defined) return" constructs and changing GLOBALS[AA_INC_PATH] to GLOBALS["AA_INC_PATH"]

Revision 1.5  2003/01/27 13:51:04  jakubadamek
fixed language constants

Revision 1.4  2003/01/21 07:02:05  mitraearth
*** empty log message ***

Revision 1.3  2002/06/17 22:09:14  honzam
removed call-time passed-by-reference variables in function calls; better variable handling if magic_qoutes are not set (no more warning displayed)

Revision 1.2  2001/12/21 11:44:56  honzam
fixed bug of includes in e-mail notify

Revision 1.1  2001/12/18 11:29:40  honzam
database conversion script - working version

*/
page_close();
?>