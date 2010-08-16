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

// APC AA - Module links main administration page

// used in init_page.php3 script to include config.php3 from the right directory

require_once "../../include/init_page.php3";
require_once AA_INC_PATH. "varset.php3";
require_once AA_INC_PATH. "formutil.php3";
require_once AA_INC_PATH. "itemview.php3";
require_once AA_INC_PATH. "item.php3";
require_once AA_INC_PATH. "pagecache.php3";
require_once AA_BASE_PATH."modules/links/linksearch.php3";
require_once AA_BASE_PATH."modules/links/constants.php3";
require_once AA_BASE_PATH."modules/links/actions.php3";
require_once AA_INC_PATH. "manager.class.php3";
require_once AA_BASE_PATH."modules/links/cattree.php3";
require_once AA_BASE_PATH."modules/links/util.php3";      // module specific utils


// Check permissions for this page.
// You should change PS_MODP_EDIT_POLLS permission to match the permission in your
// module. See /include/perm_core.php3 for more details

if ( !IfSlPerm(PS_LINKS_EDIT_LINKS) ) {
    MsgPage($sess->url(self_base())."index.php3", _m("No permissions to edit links"));
    exit;
}

/** Special function used for setting of additional searchbar properties */
function Links_UpdateStateFromFilter() {
    global $r_state;
    $r_state["show_subtree"] = ($_REQUEST['show_subtree'] ? true : false);
    $r_state['cat_id']       = $_REQUEST['cat_id'];
    $r_state['cat_path']     = GetCategoryPath( $r_state['cat_id'] );
}

/** Handler for GoCateg switch - switch to another category */
function Links_GoCateg($value, $param) {
    global $r_state;
    // TODO - refresh permission for new category!!!
    $cpath = GetCategoryPath( $value );
    if ( IsCatPerm( PS_LINKS_EDIT_LINKS, $cpath ) ) {
        $r_state["show_subtree"] = false;
        $r_state['cat_id']       = $value;
        $r_state['cat_path']     = $cpath;
    }
}

/** Handler for Tab switch - switch between bins */
function Links_Tab($value, $param) {
    global $manager;
    $GLOBALS['r_state']['bin']       = $value;
    $manager->go2page(1);
}

/** Handler for GoBookmark switch - set the state from bookmark */
function Links_GoBookmark($value, $param) {
    global $manager, $links_info;

    $start_id   = $links_info['start_id'];
    $start_path = GetCategoryPath( $start_id );

    switch( (string)$value ) {
        case '1':             // all links
            $GLOBALS['r_state']['show_subtree']    = 1;
            $GLOBALS['r_state']['cat_id']          = $start_id;
            $GLOBALS['r_state']['cat_path']        = $start_path;
            $GLOBALS['r_state']['start_path']      = $start_path;
            $GLOBALS['r_state']['tree_start_path'] = $links_info['tree_start'];
            $GLOBALS['r_state']['bin'] = 'app';
            $manager->resetSearchBar();
            $manager->addOrderBar(  array(0=>array('name'=>'a')) );
            $manager->addSearchBar( array(0=>array('name'=>1, 'value'=>'', 'operator'=>'RLIKE')) );
            break;
        case '2':             // links to check
            $GLOBALS['r_state']['show_subtree']    = 1;
            $GLOBALS['r_state']['cat_id']          = $start_id;
            $GLOBALS['r_state']['cat_path']        = $start_path;
            $GLOBALS['r_state']['start_path']      = $start_path;
            $GLOBALS['r_state']['tree_start_path'] = $links_info['tree_start'];
            $GLOBALS['r_state']['bin'] = 'app';
            $manager->resetSearchBar();
            $manager->addOrderBar(  array(0=>array('checked'=>'a')) );
            $manager->addSearchBar( array(0=>array('name'=>1, 'value'=>'', 'operator'=>'RLIKE')) );
            break;
        case '3':             // last edited links
            $GLOBALS['r_state']['show_subtree']    = 1;
            $GLOBALS['r_state']['cat_id']          = $start_id;
            $GLOBALS['r_state']['cat_path']        = $start_path;
            $GLOBALS['r_state']['start_path']      = $start_path;
            $GLOBALS['r_state']['tree_start_path'] = $links_info['tree_start'];
            $GLOBALS['r_state']['bin'] = 'app';
            $manager->resetSearchBar();
            $manager->addOrderBar(  array(0=>array('last_edit'=>'d')) );
            $manager->addSearchBar( array(0=>array('name'=>1, 'value'=>'', 'operator'=>'RLIKE')) );
            break;
    }
}

/** Handler for DeleteTrash switch - removes links from trash */
function Links_DeleteTrash($value, $param) {
    global $db;

    // first delete the trashed links
    $SQL = 'DELETE links_links FROM links_links WHERE links_links.folder=3';
    $db->tquery($SQL);

    // now we fix all incionsistences in the database (most of them caused by previous deletion)

    // delete all proposals which do not have its counterpart in list of links
    $SQL = 'DELETE p FROM links_links AS p INNER JOIN links_changes ON p.id = links_changes.proposal_link_id LEFT JOIN links_links AS s ON s.id = links_changes.changed_link_id WHERE s.id IS NULL';
    $db->tquery($SQL);

    // delete propasal - link relation
    $SQL = 'DELETE links_changes FROM links_changes LEFT JOIN links_links ON links_changes.changed_link_id = links_links.id WHERE links_links.id IS NULL';
    $db->tquery($SQL);

    // delete all unmatched language relations
    $SQL = 'DELETE links_link_lang FROM links_link_lang LEFT JOIN links_links ON links_link_lang.link_id = links_links.id WHERE links_links.id IS NULL';
    $db->tquery($SQL);

    // delete all unmatched region relations
    $SQL = 'DELETE links_link_reg FROM links_link_reg LEFT JOIN links_links ON links_link_reg.link_id = links_links.id WHERE links_links.id IS NULL';
    $db->tquery($SQL);

    // and now remove all category assignments for deleted links
    $SQL = 'DELETE links_link_cat FROM links_link_cat LEFT JOIN links_links ON links_link_cat.what_id = links_links.id WHERE links_links.id IS NULL';
    $db->tquery($SQL);
}

/** Function corresponding with 'actions' (see below) - returns true if user
 *  has the permission for the action. (The function must be called right
 *  before we perform/display action in order we have all variables set (r_state)
 *
 * @param string $action action to be displayed (in selectbox) / performed
 * @return bool true if user has the permission
 */
function Links_IsActionPerm($action) {
    $cid = $GLOBALS['r_state']['cat_path'];
    $subtree = $GLOBALS['r_state']['show_subtree'];
    $current_bin = $GLOBALS['r_state']['bin'];

    switch($action) {
        case 'Check':       return  IsCatPerm( PS_LINKS_CHECK_LINK, $cid );
        case 'Highlight':   return  !$subtree && IsCatPerm( PS_LINKS_HIGHLIGHT_LINK, $cid );
        case 'DeHighlight': return  !$subtree && IsCatPerm( PS_LINKS_HIGHLIGHT_LINK, $cid );
        case 'Delete':      return  !$subtree && IsCatPerm( PS_LINKS_DELETE_LINK, $cid );
        case 'Refuse':      return  !$subtree && IsCatPerm( PS_LINKS_DELETE_LINK, $cid );
        case 'Approve':     return  !$subtree && IsCatPerm( PS_LINKS_ADD_LINK, $cid );
        case 'Folder2':     return  ($current_bin != 'folder2') && IsCatPerm( PS_LINKS_LINK2FOLDER, $cid );
        case 'Folder3':     return  ($current_bin != 'folder3') && IsCatPerm( PS_LINKS_LINK2FOLDER, $cid );
        case 'Activate':    return  (substr($current_bin,0,6) == 'folder') && IsCatPerm( PS_LINKS_LINK2ACT, $cid );
        case 'Add2Cat':     return  true;
        case 'Move2Cat':    return !$subtree && IsCatPerm( PS_LINKS_DELETE_LINK, $cid );
        case 'DeleteTrash': return  IsSuperadmin();
        case 'GoCateg':     return  true;
        case 'Tab':         return  true;
        case 'GoBookmark':  return  true;
    }
    return false;
}

function Links_CountLinkInBins($cat_path) {
    global $db;
    // unasigned
    $SQL = 'SELECT count(DISTINCT links_links.id) as count FROM links_links
              LEFT JOIN links_link_cat ON links_links.id = links_link_cat.what_id
             WHERE (links_link_cat.category_id IS NULL AND (links_links.folder<3))';
    $db->tquery($SQL);
    if ( $db->next_record() ) {
        $ret['unasigned'] = $db->f('count');
    }

    // unasigned - trashed
    $SQL = 'SELECT count(DISTINCT links_links.id) as count FROM links_links
              LEFT JOIN links_link_cat ON links_links.id = links_link_cat.what_id
             WHERE (links_link_cat.category_id IS NULL AND (links_links.folder=3))';
    $db->tquery($SQL);
    if ( $db->next_record() ) {
        $ret['unasigned3'] = $db->f('count');
    }

    // new
    $SQL = "SELECT  count(DISTINCT links_links.id) as count
              FROM links_link_cat, links_categories, links_links
             WHERE links_links.id = links_link_cat.what_id
               AND links_link_cat.category_id = links_categories.id
               AND ((path = '$cat_path') OR (path LIKE '$cat_path,%'))
               AND (links_link_cat.proposal = 'y')
               AND (links_link_cat.base = 'y')
               AND (links_links.folder < 2)";
    $db->tquery($SQL);
    if ( $db->next_record() ) {
        $ret['new'] = $db->f('count');
    }

    // app
    $linkcounter = new linkcounter;
    $ret['app']  = $linkcounter->get_link_count($cat_path);

    // changed
    $SQL = "SELECT  count(DISTINCT links_links.id) as count
              FROM links_link_cat, links_categories, links_links
              LEFT JOIN links_changes ON links_links.id = links_changes.changed_link_id
             WHERE links_links.id = links_link_cat.what_id
               AND links_link_cat.category_id = links_categories.id
               AND ((path = '$cat_path') OR (path LIKE '$cat_path,%'))
               AND (   (     ( (links_link_cat.proposal = 'y')
                            OR (links_link_cat.proposal_delete = 'y'))
                         AND (links_link_cat.state <> 'hidden')
                         AND (links_link_cat.base = 'n'))
                     OR
                       (     (links_changes.rejected <> 'y')
                         AND (links_link_cat.base = 'y')
                         AND (links_link_cat.proposal = 'n')))
               AND (links_links.folder < 2)";
    $db->tquery($SQL);
    if ( $db->next_record() ) {
        $ret['changed'] = $db->f('count');
    }

        // folders
        // prepare
    $ret['folder0'] = $ret['folder1'] = $ret['folder2'] = $ret['folder3'] = 0;
    $SQL = "SELECT count(DISTINCT links_links.id) as count, links_links.folder
              FROM links_links, links_link_cat, links_categories
             WHERE links_links.id = links_link_cat.what_id
               AND links_link_cat.category_id = links_categories.id
               AND ((path = '$cat_path') OR (path LIKE '$cat_path,%'))
               AND links_links.folder > 1
             GROUP BY links_links.folder";
    $db->tquery($SQL);
    while ( $db->next_record() ) {
        $ret['folder'.($db->f('folder'))] = $db->f('count');
    }

    return $ret;
}

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id   = $slice_id;
$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database
$links_info  = GetModuleInfo($module_id,'Links');

$manager_settings = array(
     'searchbar' => array(
         'fields'               => GetLinkFields(),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'show_bookmarks'       => false,
         'function'             => 'Links_UpdateStateFromFilter',  // aditional action hooked on standard filter action
         'hint'                 => _m("HINT: \"social ecology\" AND environment"),
         'hint_url'             => get_help_url(AA_LINKS_HELP_MAIN,"hledat-radit")
                         ),
     'scroller'  => array(
         'listlen'              => ($listlen ? $listlen : EDIT_ITEM_COUNT),
         'slice_id'             => $slice_id
                         ),
     'itemview'  => array(
         'manager_vid'          => $links_info['manager_vid'],      // id of view which controls the design
         'format'               => array(                           // optionaly to manager_vid you can set format array
             'compact_top'      => "<table border=0 cellspacing=0 cellpadding=5>",
             'category_sort'    => false,
             'category_format'  => "",
             'category_top'     => "",
             'category_bottom'  => "",
             'even_odd_differ'  => false,
             'even_row_format'  => "",
             'odd_row_format'   => '<tr class=tabtxt><td width="30"><input type="checkbox" name="chb[_#LINK_ID_]" value=""></td><td class=tabtxt><a href="_#EDITLINK">{switch({_#L_NAME__}).:_#L_NAME__:???}</a> (_#L_O_NAME)<div class="tabsmall">_#L_DESCRI<br>(_#CATEG_GO)<br><a href="_#L_URL___" target="_blank">_#L_URL___</a></div></td><td class=tabsmall>{alias:checked:f_d:j.n.Y}<br>{alias:created_by:f_e:username}<br>{alias:edited_by:f_e:username}<br><span style="background:#_#L_VCOLOR;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;_#L_VALID_&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></td></tr>
             ',
             'compact_remove'   => "()",
             'compact_bottom'   => "</table>",
             'id'               => $link_info['id'] ),
         'fields'               => '',
         'aliases'              => GetLinkAliases(),
         'get_content_funct'    => 'Links_GetLinkContent'
                         ),
     'actions_perm_function' => 'Links_IsActionPerm',
     'actions_hint' => _m("Perform action on selected items"),
     'actions_hint_url' => get_help_url(AA_LINKS_HELP_MAIN,"co-udelat-sodkazy"),
     'actions'   => array(
         'Check'       => array('function'   => 'Links_CheckLink',
                                'name'       => _m('Check Link'),
                                'type'       => 'one_by_one' ),
         'Highlight'   => array('function'   => 'Links_HighlightLink',
                                'name'       => _m('Highlight Link'),
                                'type'       => 'one_by_one' ),
         'DeHighlight' => array('function'   => 'Links_DeHighlightLink',
                                'name'       => _m('Dehighlight Link'),
                                'type'       => 'one_by_one' ),
         'Delete'      => array('function'   => 'Links_DeleteLink',
                                'name'       => _m('Remove from category'),
                                'type'       => 'one_by_one' ),
         'Activate'    => array('function'   => 'Links_ActivateLink',
                                'name'       => _m('Move to Active'),
                                'type'       => 'one_by_one' ),
         'Folder2'     => array('function'   => 'Links_FolderLink',
                                'func_param' => 2,
                                'name'       => _m('Move to Holding bin'),
                                'type'       => 'one_by_one' ),
         'Folder3'     => array('function'   => 'Links_FolderLink',
                                'func_param' => 3,
                                'name'       => _m('Move to Trash'),
                                'type'       => 'one_by_one' ),
         'Add2Cat'     => array('function'   => 'Links_Add2CatLink',
                                'name'       => _m('Add to category'),
                                'open_url'   => $sess->url("getcat.php3?start=".GetCategoryFromPath($links_info['tree_start'])),
                                'type'       => 'one_by_one' ),
         'Move2Cat'    => array('function'   => 'Links_Move2CatLink',
                                'name'       => _m('Move to category'),
                                'open_url'   => $sess->url("getcat.php3?start=".GetCategoryFromPath($links_info['tree_start'])),
                                'type'       => 'one_by_one' ),
/*       'Refuse'      => array('function'   => 'Links_RefuseLink',
                                'name'       => _m('Refuse Link'),
                                'type'       => 'one_by_one' ),
         'Approve'     => array('function'   => 'Links_ApproveLink',
                                'name'       => _m('Approve Link'),
                                'type'       => 'one_by_one' ),
         'app'         => array('function'   => 'MoveItems',
                                'name'       => _m('app'),
                                'func_param' => 1,
                                'perm'       => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_POLLS2ACT)),
         'hold'        => array('function'   => 'MoveItems',
                                'name'       => _m('hold'),
                                'func_param' => 2,
                                'perm'       => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_POLLS2HOLD)),
         'trash'       => array('function'   => 'MoveItems',
                                'name'       => _m('trash'),
                                'func_param' => 3,
                                'perm'       => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_POLLS2TRASH))  */
                         ),
     'switches'  => array(
         'DeleteTrash' => array('function'   => 'Links_DeleteTrash'),
         'GoCateg'     => array('function'   => 'Links_GoCateg'),
         'Tab'         => array('function'   => 'Links_Tab'),
         'GoBookmark'  => array('function'   => 'Links_GoBookmark')
                         ),
     'messages'  => array(
         'title'            => _m('ActionApps - Links Manager'))
                         );

if ( $change_id OR ($r_state["module_id"] != $module_id)) {
    // we are here for the first time or we are switching to another slice
    unset($r_state);
    // set default admin interface settings from user's profile
    // TODO - set defaults
    $r_state["module_id"]       = $module_id;
    $r_state["show_subtree"]    = false;
    $r_state['cat_id']          = $links_info['start_id'];
    $r_state['cat_path']        = GetCategoryPath( $links_info['start_id'] );
    $r_state['start_path']      = GetCategoryPath( $links_info['start_id']);
    $r_state['tree_start_path'] = $links_info['tree_start'];
    $r_state['bin']             = 'app';
    $sess->register('r_state');
}

$manager_id = 'links'. $module_id;  // no special meaning - just manager id

$manager = new AA_Manager($manager_id, $manager_settings);
$manager->performActions();
$manager->printHtmlPageBegin();  // html, head, css, title, javascripts

// additional code for searchbar - category selection, ...
// still in <head>
// js needed for category selection

$tree = new cattree( $db, $links_info['start_id'], true, '<br> - ');
FrmJavascriptFile('javascript/js_lib.js');
FrmJavascriptFile('javascript/js_lib_links.js');   // js for category selection
$tree->printTreeData($links_info['start_id'],1,true);
$cat_tree = $tree->getFrmTree(false, 'change', $links_info['start_id'],
                   'patharea', 'document.filterform.cat_id', false, 250, 8, 'filterform');

$r_state['bin_cnt'] = Links_CountLinkInBins($r_state['start_path']);

echo '<script language="JavaScript" type="text/javascript"> <!--
  // shortcut function for GoToCategoryID
  function SwitchToCat(cat) {
    GoToCategoryID(cat, eval(\'document.filterform.tree\'), \'patharea\', \'document.filterform.cat_id\');
  }

  function EditCurrentCat() {
      document.location = "'.$sess->url('catedit.php3').'&cid=" + document.filterform.cat_id.value;
  }
  //-->
  </script>
</head>';

// This is not definitive place for Bookmarks definition. It will be in database
// (probably in User profiles)
$bookmarks[1] = _m('All my links');
$bookmarks[2] = _m('Links to check');
$bookmarks[3] = _m('Last edited');

require_once AA_BASE_PATH."modules/links/menu.php3";
showMenu($aamenus, "linkmanager", $r_state['bin'], $navbar!="0", $leftbar!="0");

$conds = $manager->getConds();
$sort  = $manager->getSort();

//links_link_cat.state <> 'hidden'
//print_r($r_state);

$link_zids=Links_QueryZIDs($r_state['cat_path'], $conds, $sort, $r_state["show_subtree"], $r_state['bin']);

$manager->printSearchbarBegin();

// special code extending searchbar - category selection
echo '<table width="100%" border="0" cellspacing="0" cellpadding="3"
              class=leftmenu bgcolor="'. COLOR_TABBG .'">';
echo '<tr>
       <td class="search" width="255" align="center">
         <input type="hidden" name="cat_id" value="'.$r_state['cat_id']."\">
         $cat_tree
       </td>
       <td width='99%'>";
echo '<table width="100%" border="0" cellspacing="0" cellpadding="3"
              class=leftmenu bgcolor="'. COLOR_TABBG .'">';
echo '<tr><td><div id="patharea">'. ' '. '</div> </td></tr>';
FrmInputButtons( array( 'gocat'    => array('type'=>'button',
                                            'value'=>_m('Show Links'),
                                            'add'=>'onclick="document.filterform.submit()"',
                                            'help'=>get_help_url(AA_LINKS_HELP_MAIN,"zobraz-odkazy")),
                        'editcat'  => array('type'=>'button',
                                            'value'=>_m('Edit Category'),
                                            'add'=>'onclick="EditCurrentCat()"',
                                            'help'=>get_help_url(AA_LINKS_HELP_MAIN,"editujkat"))
//                        'bookmark' => array('type'=>'button',
//                                            'value'=>_m('Bookmark'))
                      ),  false, false, 'bottom');

echo '<tr><td class=tabtxt>';
FrmChBoxEasy("show_subtree", $r_state["show_subtree"]);
echo _m('Show subtree links');
echo FrmMoreHelp(get_help_url(AA_LINKS_HELP_MAIN,"zobraz-odkazy")). ' </td></tr>';

echo "</table></td></tr>";
echo "<tr><td>".FrmMoreHelp(get_help_url(AA_LINKS_HELP_MAIN,"seznamkat"))."</td></tr>";
echo "</table>";

$manager->printSearchbarEnd();   // close the searchbar form

// prints JavaScript which changes tree to current cat.
echo $tree->goCategory($r_state['cat_id'], 'patharea', 'document.filterform.cat_id', 'filterform');

PrintArray($r_err);
PrintArray($r_msg);
unset($r_err);
unset($r_msg);

$manager->printItems($link_zids);   // print links and actions
$r_state['manager']    = $manager->getState();
$r_state['manager_id'] = $manager_id;  // no special meaning - just manager id

HtmlPageEnd();
page_close();
?>
