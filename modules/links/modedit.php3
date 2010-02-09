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

/* Params:
    $template['Links'] .. id of site template - new will be based on this one
    $update=1 .. write changes to database
*/

$LINKS_LANGUAGE_FILES = array( "en_links_lang.php3" => "en_links_lang.php3",
                               "cz_links_lang.php3" => "cz_links_lang.php3");


if ($template['Links']) {
    $no_slice_id = true;       // message for init_page.php3
}

require_once "../../include/init_page.php3";
//require_once AA_INC_PATH."en_links_lang.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."date.php3";
require_once AA_INC_PATH."modutils.php3";
require_once AA_INC_PATH."mgettext.php3";
require_once AA_BASE_PATH."modules/links/util.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset      = new CVarset();
$superadmin  = IsSuperadmin();
$module_id   = $slice_id;


if ($template['Links']) {        // add module
    if (!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
        MsgPage($sess->url(self_base())."index.php3", _m('No permission to add module'), "standalone");
        exit;
    }
} else {                    // edit module
    if (!CheckPerms( $auth->auth["uid"], "slice", $module_id, PS_LINKS_SETTINGS)) {
        MsgPage($sess->url(self_base())."index.php3", _m('No permission to edit module'), "standalone");
        exit;
    }
}

if ( ($insert AND $superadmin) OR $update ) {
    do {
        if ( !$owner )  { // insert new owner
            if ( !( $owner = CreateNewOwner($new_owner, $new_owner_email, $err, $varset, $db))) {
                break;
            }
        }

        // validate all fields needed for module table (name, slice_url, lang_file, owner)
        ValidateModuleFields( $name, $slice_url, $priority, $lang_file, $owner, $err );
        $deleted  = ( $deleted  ? 1 : 0 );

        // now validate all module specific fields
        ValidateInput("start_id", _m("Start category id"), $start_id, $err, false, "number");
        ValidateInput("tree_start", _m("Tree start id"), $tree_start, $err, false, "number");
        ValidateInput("select_start", _m("Select start id"), $select_start, $err, false, "number");

        if ( count($err) > 1) {
            break;
        }

        // write all fields needed for module table
        $module_id = WriteModuleFields(($update && $module_id) ? $module_id : false, $superadmin, 'Links', $name, $slice_url, $priority, $lang_file, $owner, $deleted, Links_Category2SliceID($start_id));
        if ( !$module_id ) {       // error?
            break;
        }
        $slice_id = $module_id;

        // now set all module specific settings
        if ( $update AND $superadmin) {
            // tree_start and select_start could be changed only by superadmin
            // Maybe we could allow also administrators to change $select_start in
            // future

            $p_module_id = q_pack_id($module_id);

            $varset->clear();

            // we can't change start_id (module_id is derived from it)
            //      $varset->add("start_id", "number", $start_id);
            $varset->add("tree_start", "number", $tree_start);
            $varset->add("select_start", "number", $select_start);

            // defaults - we use the same table for all links. The setting of defaults to 1
            // flags this as default for this poll module
            $SQL = "UPDATE links SET ". $varset->makeUPDATE() . " WHERE (id='$p_module_id')";
            if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
                $err["DB"] = MsgErr("Can't change links table");
                break;
            }
        } elseif ($insert AND $superadmin) { // insert (add)
            $varset->clear();

            $p_template_id = ( $template['Links'] ?
            q_pack_id(substr($template['Links'],1)) : 'LinksTemplate...' );

            $SQL = "SELECT * FROM links WHERE (id='$p_template_id')";
            $db->query($SQL);
            if ( !$db->next_record() ) {
                $err["DB"] = MsgErr("Bad template id");
                break;
            }
            $varset->setFromArray($db->Record);
            $varset->set("id", $module_id, "unpacked");
            $varset->set("start_id", $start_id, "number");
            $varset->set("tree_start", $tree_start, "number");
            $varset->set("select_start", $select_start, "number");
            //      $varset->set("default_cat_tmpl", $default_cat_tmpl, "quoted");
            //      $varset->set("link_tmpl", $link_tmpl, "quoted");

            // create new links
            if ( !$db->query("INSERT INTO links" . $varset->makeINSERT() )) {
                $err["DB"] .= MsgErr("Can't add links");
                break;
            }
        }
        $GLOBALS['pagecache']->invalidateFor("slice_id=$module_id");  // invalidate old cached values for this slice
    } while (false);

    if ( count($err) <= 1 ) {
        page_close();                                // to save session variables
        $netscape = (($r=="") ? "r=1" : "r=".++$r);  // special parameter for Netscape to reload page
        // added by Setu, 2002-0227
        if ($return_url) {  // after work for action, if return_url is there, we go to the page.
            go_url(urldecode($return_url));
        }
        go_url($sess->url(self_base() . "modedit.php3?$netscape"));
    }
}


// And the form -----------------------------------------------------

$source_id   = ($template['Links'] ? substr($template['Links'],1) : $module_id );
$p_source_id = q_pack_id( $source_id );

// load module common data
list( $name, $slice_url, $priority, $lang_file, $owner, $deleted, $slice_owners ) =
                                           GetModuleFields( $source_id, $db );
// load module specific data
$SQL= " SELECT * FROM links WHERE id='$p_source_id'";

$db->query($SQL);
if ($db->next_record()) {
    while (list($key,$val,,) = each($db->Record)) {
        if (!is_numeric($key)) {
            $$key = $val; // variables and database fields have identical names
        }
    }
}
$id = unpack_id($db->f("id"));  // correct ids

if ( $template['Links'] ) {           // set new name and owner for NEW module
    $name  = "";
    $owner = "";
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m('ActionApps - Links Admin')?></TITLE>
</HEAD>
<?php
  require_once AA_BASE_PATH."modules/links/menu.php3";
  showMenu($aamenus, "modadmin", "main");

  echo "<H1><B>" . ( $template['Links'] ? _m("Add Links module") : _m("Edit Links module")) . "</B></H1>";
  PrintArray($err);
  echo $Msg;

  echo '<form method=post action="'. $sess->url($_SERVER['PHP_SELF']) .'">';

  FrmTabCaption( _m('Module Links data') );
  $include_cmd = "<!--#include virtual=\"". AA_INSTAL_PATH ."modules/links/links.php3?link_id=$module_id\"-->";
  FrmStaticText(_m('ID'), $module_id);
  FrmInputText("name", _m('Title'), $name, 99, 25, true);
  FrmInputText("slice_url", _m('URL of .shtml page'), $slice_url, 254, 25, false,
               _m("Use following SSI command to include links to the page: "). $include_cmd);
  FrmInputText("priority", _m("Priority (order in slice-menu)"), $priority, 5, 5, false);
  FrmInputSelect("owner", _m('Owner'), $slice_owners, $owner, false);
  if ( !$owner ) {
    FrmInputText("new_owner", _m('New Owner'), $new_owner, 99, 25, false);
    FrmInputText("new_owner_email", _m('New Owner\'s E-mail'), $new_owner_email, 99, 25, false);
  }
  if ( $superadmin ) {
    FrmInputChBox("deleted", _m('Deleted'), $deleted);
  }
  FrmInputSelect("lang_file", _m('Used Language File'), $LINKS_LANGUAGE_FILES, $lang_file, false);

// module specific...
  if ($template['Links']) {
      FrmInputText("start_id", _m("Start category id"), $start_id);
  } else {
      // we can't change start_id (module_id is derived from it)
      FrmStaticText(_m('Start category id'), $start_id);
  }
  if ( $superadmin ) {
      FrmInputText("tree_start", _m("Tree start id"), $tree_start);
      FrmInputText("select_start", _m("Select start id"), $select_start);
  } else {
      FrmStaticText( _m("Tree start id"),   $tree_start);
      FrmStaticText( _m("Select start id"), $select_start);
  }

  if ( $template['Links'] ) {
    FrmTabEnd( array( 'insert',
                      'template[Links]' => array( 'type'=>"hidden",
                                                  'value'=> $template['Links'])),
               $sess, $slice_id);
  } else {
    FrmTabEnd( array( 'update', 'reset', 'cancel' ), $sess, $slice_id );
  }

  echo '</FORM>
      </BODY>
    </HTML>';
  page_close()
?>
