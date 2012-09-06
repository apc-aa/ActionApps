<?php  //catedit2.php3 - proceeds submitted data from catedit.php3
//$Id$

require_once "../../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_BASE_PATH."modules/links/constants.php3";
require_once AA_BASE_PATH."modules/links/util.php3";           // module specific utils

$r_state['linkedit']['old'] = $_POST;  // in case of bad input
unset($r_msg);
unset($r_err);
// Function definitions --------------------------------------------------------

// Checks if category has subcategories or links
// (for deletion, so it does not count links in trash and it also do not count
//  proposals) - It is question is we haven't to count proposals
function IsCatEmpty($category_id) {
  global $db;
//  $path = GetCategoryPath($category_id);
//  $app_zids  = Links_QueryZIDs($path, '', '', true, 'app');
//  $hold_zids = Links_QueryZIDs($path, '', '', true, 'folder2');
//  $cat_zids  = Links_QueryCatZIDs($path, '', '', true, 'app');

  $SQL = " SELECT links_links.id
           FROM links_link_cat, links_links
          WHERE links_link_cat.what_id = links_links.id
            AND links_link_cat.category_id = $category_id
            AND links_links.folder < 3
            AND NOT (links_link_cat.state = 'hidden')
            AND links_link_cat.proposal = 'n'";


  $db->query($SQL);
  if ( $db->next_record() )
    return false;

  $SQL = "SELECT what_id FROM links_cat_cat
                         WHERE (category_id = $category_id)";
  $db->query($SQL);
  return !($db->next_record());
}

// Delete one category assignment
function DeleteCatAssignment($parent, $child) {
  global $db;
  $SQL = "DELETE FROM links_cat_cat
           WHERE what_id = $child
             AND category_id =$parent";

  $db->query( $SQL );
}

// Delete one category
function DeleteCategory($catId) {
  global $db;

  $SQL = "DELETE FROM links_link_cat
           WHERE category_id = $catId";
  $db->tquery( $SQL );

  $SQL = "DELETE FROM links_cat_cat
           WHERE category_id = $catId";
  $db->tquery( $SQL );

  $SQL = "DELETE FROM links_categories
           WHERE id = $catId";
  $db->tquery( $SQL );
}

function ChangeCatPriority($category_id, $insertedId, $pri, $state, $name) {
    global $db;

    // General categories have its own priorities
    $new_pri = Links_GlobalCatPriority($name);
    if ( $new_pri ) $pri = $new_pri;
    $SQL = "UPDATE links_cat_cat SET priority=$pri, state='$state'
              WHERE category_id = $category_id
                    AND what_id = $insertedId";

    $db->query( $SQL );
}

function ChangeCatState($category_id, $insertedId, $state) {
    global $db;

    // General categories have its own priorities
    $SQL = "UPDATE links_cat_cat SET state='$state'
              WHERE category_id = $category_id
                    AND what_id = $insertedId";

    $db->query( $SQL );
}

// Moves this category to another subtree or delete (if clear and no link to it)
function UnassignBaseCategory($parent, $child) {
    global $db, $r_msg, $r_err;

    // get all categories, where this is subcategory
    $SQL = "SELECT category_id, base FROM links_cat_cat
                         WHERE (what_id = $child)";

    $db->query($SQL);

    while ( $db->next_record() ) {  // this base category is linked to another
        if ( $db->f('category_id') == $parent )  // this we unasign => skip it
            continue;

        $newParent = $db->f('category_id');

        // make first as base
        $SQL = "UPDATE links_cat_cat SET base='y'
             WHERE what_id = $child
               AND category_id = $newParent";

        $db->query( $SQL );

        // we have to change path to this category
        $SQL = "SELECT path FROM links_categories
             WHERE id = $newParent";
        $db->query( $SQL );
        if ( $db->next_record() )
          $newPath = $db->f('path');


        $SQL = "SELECT path FROM links_categories
             WHERE id = $child";
        $db->query( $SQL );
        if ( $db->next_record() )
          $oldPath = $db->f('path');

        if ($newPath && $oldPath) {
            $SQL = "UPDATE links_categories
                       SET path = REPLACE(path, '$oldPath' , '$newPath,$child')
                     WHERE path  LIKE '$oldPath%'";
            $db->query( $SQL );
        } else {
            huhl("Something very strange in UnassignBaseCategory() in catedit2.php3");
            exit;
        }

        // delete current assignment
        DeleteCatAssignment($parent, $child);
        ChangeCatPermAsIn($child, $newParent);

        $r_msg[] = MsgOK(_m('Category reassigned'));
        return;
    }
    // category is not linked to another => delete if clear
    if ( IsCatEmpty($child) ) {
        DeleteCategory($child);
        DeleteCatAssignment($parent, $child);
        DelPermObject($child, 'category');

        $r_msg[] = MsgOK(_m('Subcategory deleted'));
    } else {
        $r_err[] = MsgErr(_m('Can\'t delete category which contains links'));
    }

    return;
}

function GetPureName($name, &$state) {
    if (($length=strlen($name)) >= 4) {
        switch (substr($name,0,4)) {
            case "(!) ":
                $state = "'highlight'";
                break;
            case "(-) ":
                $state = "'visible'";
                break;
            default:
                $state = "'visible'";
                return $name;
        }
        return $length > 4 ? substr($name, -$length+4, $length-4) : "";
    }
    $state = "'visible'";
    return $name;
}

// End of function definitions -------------------------------------------------

if ($cancel)
    go_url( $sess->url(self_base(). "index.php3"));


// we allways sending cat id - it prevent us of bug with 'Back' browser button
if ( $cid )
   $r_state['cat_id'] = $cid;

$cpath = GetCategoryPath( $cid );
if ( IsCatPerm( PS_LINKS_EDIT_CATEGORY, $cpath ) ) {
    $r_state['cat_path']  = $cpath;
} else {
    MsgPage($sess->url(self_base())."index.php3", _m('No permission to edit category'));
    exit;
}

$r_err[0] = "";            // error array (just for initializing variable)
$varset = new Cvarset();

// Category properties ------------------

ValidateInput("cat_name",    _m('Category name'),          $cat_name,    $r_err, true,  "text");
ValidateInput("description", _m('Category description'),   $description, $r_err, false, "text");
//  we use additional field to display some special text on the category
// (actually right column), so we need to not modify it by user - Honza
ValidateInput("additional",  _m('Additional information'), $additional,  $r_err, false, "text");
ValidateInput("note",        _m('Editor\'s note'),         $note,        $r_err, false, "text");

if (count($r_err) <= 1) {
    $varset->add("name",        "quoted", $cat_name);
    $varset->add("description", "quoted", $description);
    $varset->add("additional",  "quoted", $additional);
    $varset->add("note",        "quoted", $note);
    $varset->add("nolinks",     "quoted", $nolinks ? 1 : 0);
    $db->query("UPDATE links_categories SET ". $varset->makeUPDATE() . " WHERE id=$cid");
    $r_msg[] = MsgOK(_m('Category data changed'));
} else {
    page_close();
    go_url( $sess->url(self_base() . "catedit.php3"));
}

// Procces subcategories ---------------------

//subcatIds contains cross delimeted list of selected subcategories ids
$ids = explode(",",$subcatIds);

//subcatNames contains cross delimeted list of  selected subcategories names
$names = explode("#",$subcatNames);

//subcatStates contains cross delimeted list of selected subcategories states
$states = explode("#",$subcatStates);

// First we delete all not owned (not base) subcategories

$SQL = "DELETE FROM links_cat_cat
               WHERE category_id=$cid
                 AND base = 'n'";

$db->tquery($SQL);

// Lookup old base subcategories
$SQL = "SELECT what_id FROM links_cat_cat
               WHERE category_id=$cid";  // no necessary to add base='y'

$db->tquery($SQL);                            //    - 'n' was deleted

while ( $db->next_record() )
    $oldBaseSubcat[$db->f('what_id')] = $db->f('what_id');

// Lookup new not base subcategories
if ($subcatIds) {
    $SQL = "SELECT what_id FROM links_cat_cat
                         WHERE (what_id IN ($subcatIds))
                           AND (base='y')
                           AND (category_id<>$cid)";

    $db->tquery($SQL);

    while ( $db->next_record() )
        $newNotBaseSubcat[$db->f('what_id')] = $db->f('what_id');
}

if (isset($ids) && is_array($ids) && $subcatIds!="") {
    reset($ids);
    $pri = 0.0;

    while ( list($key, $insertedId) = each($ids) ) {
        $pri += 1.0;

        // new subcategory
        if ( $insertedId == 0 ) {
            $foo_id = Links_AddCategory($names[$key], $cid, $cpath);

            // adds perms too
            Links_AssignCategory($cid, $foo_id, $pri, LINKS_BASE_CAT, $states[$key]);
            $r_msg[] = MsgOK( _m('New category created') );
        }

        // existing but not base in here
        elseif ($newNotBaseSubcat[$insertedId]) {
            Links_AssignCategory($cid, $insertedId, $pri, LINKS_NOT_BASE_CAT, $states[$key]);
            $r_msg[] = MsgOK( _m('Foreign category assigned') );
        }

        // existing but base (so assignment must exist before)
        else {
// commented out - Honza - priorities are not possible, now - all is displayed
// alphabeticaly. On the other hand, to add priorities just uncoment following
// line and add order changing arrows to catedit admin interface
//          ChangeCatPriority($cid, $insertedId, $pri, $states[$key], $names[$key]);
            ChangeCatState($cid, $insertedId, $states[$key]);
            $oldBaseSubcat[$insertedId] = "";      // reassigned
        }
    }
}

// unassign old base subcategories
if (isset($oldBaseSubcat) AND is_array($oldBaseSubcat) ) {
    reset($oldBaseSubcat);
    while ( list($key, $val) = each($oldBaseSubcat) ) {
        if ($val=="" )  // this category was reassigned
            continue;
            // Moves this category to another subtree or delete
            //   (if clear and no link to it)
        UnassignBaseCategory($cid, $val);
    }
}

page_close();
go_url( $sess->url(self_base() . "index.php3"));
?>