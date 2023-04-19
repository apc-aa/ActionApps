<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

// used in init_page.php script to include config.php from the right directory

use AA\Manager;
use AA\Manageraction\Links_Add2Cat;
use AA\Manageraction\Links_Check;
use AA\Manageraction\Links_Delete;
use AA\Manageraction\Links_DeleteTrash;
use AA\Manageraction\Links_GoBookmark;
use AA\Manageraction\Links_GoCateg;
use AA\Manageraction\Links_Highlight;
use AA\Manageraction\Links_Move;
use AA\Manageraction\Links_Move2Cat;
use AA\Manageraction\Links_Tab;
use AA\Manageraction\Manageractions;
use AA\Util\InputVars;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/itemview.php";
require_once __DIR__ . "/../../include/item.php";
require_once __DIR__ . "/../../include/menu_util.php";

require_once __DIR__ . "/../../modules/links/linksearch.php";
require_once __DIR__ . "/../../modules/links/constants.php";
require_once __DIR__ . "/../../modules/links/actions.php";
require_once __DIR__ . "/../../modules/links/cattree.php";
require_once __DIR__ . "/../../modules/links/util.php";      // module specific utils

try {
    // array ( 1 => 1, )
    $GoBookmark = InputVars::boolGET('GoBookmark');
    // array ( 'changed' => 7, 'app' => 17, 'folder2' => 4, )
    $Tab = InputVars::stringGET('Tab'); // alnum
    // $aa_akce_all = InputVars::stringPOST('aa_akce_all'); // empty // not necessary
    // $akce* = InputVars::stringSlashedPOST('akce'); // alnum // not necessary
    // array ( 2 => 19, 1 => 2, 3 => 18, 4 => 2, )
    $cat_id = InputVars::intGET('cat_id');
    // array ( )
    $chb = InputVars::arrayPOST('chb');
    // array ( 'Folder2' => 2, 'Activate' => 1, )
    $markedaction_select = InputVars::stringPOST('markedaction_select'); // alnum
    // array ( 'on' => 30, )
    $show_subtree = InputVars::boolGET('show_subtree');
    // $srchbr* // non necessary
    $change_id = InputVars::idGETPOST('change_id');
    $listlen = InputVars::intGET('listlen');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}


// Check permissions for this page.
// You should change PS_MODP_EDIT_POLLS permission to match the permission in your
// module. See /include/perm_core.php for more details

if ( !IfSlPerm(PS_LINKS_EDIT_LINKS) ) {
    MsgPage(_m("No permissions to edit links"));
    exit;
}

/** Special function used for setting of additional searchbar properties */
function Links_UpdateStateFromFilter() {
    global $r_state;
    $r_state["show_subtree"] = ($_REQUEST['show_subtree'] ? true : false);
    $r_state['cat_id']       = $_REQUEST['cat_id'];
    $r_state['cat_path']     = GetCategoryPath( $r_state['cat_id'] );
}

function Links_CountLinkInBins($cat_path) {
    $db = getDB();
    // unasigned
    $SQL = 'SELECT count(DISTINCT links_links.id) as count FROM links_links
              LEFT JOIN links_link_cat ON links_links.id = links_link_cat.what_id
             WHERE (links_link_cat.category_id IS NULL AND (links_links.folder<3))';
    $db->query($SQL);
    if ( $db->next_record() ) {
        $ret['unasigned'] = $db->f('count');
    }

    // unasigned - trashed
    $SQL = 'SELECT count(DISTINCT links_links.id) as count FROM links_links
              LEFT JOIN links_link_cat ON links_links.id = links_link_cat.what_id
             WHERE (links_link_cat.category_id IS NULL AND (links_links.folder=3))';
    $db->query($SQL);
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
    $db->query($SQL);
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
    $db->query($SQL);
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
    $db->query($SQL);
    while ( $db->next_record() ) {
        $ret['folder'.($db->f('folder'))] = $db->f('count');
    }
    freeDB($db);
    return $ret;
}

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id   = AA::$module_id;
$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database
$links_info  = GetModuleInfo($module_id,'Links');

$actions   = new Manageractions;
$actions->addAction(new Links_Move('Activate', 1));
$actions->addAction(new Links_Move('Folder2',  2));
$actions->addAction(new Links_Move('Folder3',  3));
$actions->addAction(new Links_Check('Check'));
$actions->addAction(new Links_Highlight('Highlight',true));
$actions->addAction(new Links_Highlight('DeHighlight', false));
$actions->addAction(new Links_Delete('Delete'));
$actions->addAction(new Links_Add2Cat('Add2Cat',   StateUrl("getcat.php?start=".GetCategoryFromPath($links_info['tree_start']))));
$actions->addAction(new Links_Move2Cat('Move2Cat', StateUrl("getcat.php?start=".GetCategoryFromPath($links_info['tree_start']))));


$switches  = new Manageractions;
$switches->addAction(new Links_DeleteTrash('DeleteTrash'));
$switches->addAction(new Links_GoCateg('GoCateg'));
$switches->addAction(new Links_Tab('Tab'));
$switches->addAction(new Links_GoBookmark('GoBookmark'));


// function - we need trnslate _m() on use (not at include time)
$manager_settings = [
     'searchbar' => [
         'fields'               => GetLinkFields(),
         'search_row_count_min' => 1,
         'order_row_count_min'  => 1,
         'add_empty_search_row' => true,
         'show_bookmarks'       => false,
         'function'             => 'Links_UpdateStateFromFilter',  // aditional action hooked on standard filter action
         'hint'                 => _m("HINT: \"social ecology\" AND environment"),
         'hint_url'             => get_help_url(AA_LINKS_HELP_MAIN,"hledat-radit")
     ],
     'scroller'  => [
         'listlen'              => ($listlen ?: EDIT_ITEM_COUNT),
         'slice_id'             => AA::$module_id
     ],
     'itemview'  => [
         'manager_vid'          => $links_info['manager_vid'],      // id of view which controls the design
         'format'               => [                           // optionaly to manager_vid you can set format array
             'compact_top'      => "<table>",
             'category_sort'    => false,
             'category_format'  => "",
             'category_top'     => "",
             'category_bottom'  => "",
             'even_odd_differ'  => false,
             'even_row_format'  => "",
             'odd_row_format'   => '<tr><td width="30"><input type="checkbox" name="chb[_#LINK_ID_]" value=""></td><td><a href="_#EDITLINK">{switch({_#L_NAME__}).:_#L_NAME__:???}</a> (_#L_O_NAME)<div class="tabsmall">_#L_DESCRI<br>(_#CATEG_GO)<br><a href="_#L_URL___" target="_blank">_#L_URL___</a></div></td><td class=tabsmall>{alias:checked:f_d:j.n.Y}<br>{alias:created_by:f_e:username}<br>{alias:edited_by:f_e:username}<br><span style="background:#_#L_VCOLOR;">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;_#L_VALID_&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span></td></tr>
             ',
             'compact_remove'   => "()",
             'compact_bottom'   => "</table>",
             'id'               => $links_info['id']
         ],
         'fields'               => '',
         'aliases'              => new AA_Aliases(new AA_Aliasgenerator_Link()),
         'get_content_funct'    => 'Links_GetLinkContent'
     ],
     'actions_hint' => _m("Perform action on selected items"),
     'actions_hint_url' => get_help_url(AA_LINKS_HELP_MAIN,"co-udelat-sodkazy"),
     'actions'   => $actions,
     'switches'  => $switches
];

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

$manager = new Manager($manager_id, (new \AA\Managed())->setSettings($manager_settings)->setTitle( _m('ActionApps - Links Manager')));
$manager->performActions();
$manager->printHtmlPageBegin();  // html, head, css, title, javascripts

// additional code for searchbar - category selection, ...
// still in <head>
// js needed for category selection

$tree = new cattree( $links_info['start_id'], true, '<br> - ');
FrmJavascriptFile('javascript/js_lib.min.js');
FrmJavascriptFile('javascript/js_lib_links.min.js');   // js for category selection
$tree->printTreeData($links_info['start_id'],1,true);
$cat_tree = $tree->getFrmTree('change', $links_info['start_id'], 'patharea', 'document.filterform.cat_id', false, 250, 8, 'filterform');

$r_state['bin_cnt'] = Links_CountLinkInBins($r_state['start_path']);

echo '<script>
  // shortcut function for GoToCategoryID
  function SwitchToCat(cat) {
    GoToCategoryID(cat, eval(\'document.filterform.tree\'), \'patharea\', \'document.filterform.cat_id\');
  }

  function EditCurrentCat() {
      window.location = "'.StateUrl('catedit.php').'&cid=" + document.filterform.cat_id.value;
  }
</script>
</head>';

// This is not definitive place for Bookmarks definition. It will be in database
// (probably in User profiles)
$bookmarks[1] = _m('All my links');
$bookmarks[2] = _m('Links to check');
$bookmarks[3] = _m('Last edited');

echo getMenu("linkmanager", $r_state['bin']);

$conds = $manager->getConds();
$sort  = $manager->getSort();

//links_link_cat.state <> 'hidden'
//print_r($r_state);

$link_zids=Links_QueryZIDs($r_state['cat_path'], $conds, $sort, $r_state["show_subtree"], $r_state['bin']);

echo $manager->getSearchbarBegin();

// special code extending searchbar - category selection
echo '<div class=flex>';
echo '<div class=search>
         <input type="hidden" name="cat_id" value="'.$r_state['cat_id']."\">
         $cat_tree
      </div>";
echo '<div id="patharea"> </div>';
echo '<div>';
echo  getFrmInputButtons([ 'gocat'   => [ 'type' => 'button', 'value' => _m('Show Links'), 'add' => 'onclick="document.filterform.submit()"']]);
echo  getFrmMoreHelp(get_help_url(AA_LINKS_HELP_MAIN, "zobraz-odkazy"));

echo  getFrmInputButtons([ 'editcat' => [ 'type' => 'button', 'value' => _m('Edit Category'), 'add' => 'onclick="EditCurrentCat()"' ]]);    // 'bookmark' => array('type'=>'button','value'=>_m('Bookmark'))
echo  getFrmMoreHelp(get_help_url(AA_LINKS_HELP_MAIN, "editujkat"));
echo  '<div>';
  FrmChBoxEasy("show_subtree", $r_state["show_subtree"]);
  echo _m('Show subtree links');
  echo getFrmMoreHelp(get_help_url(AA_LINKS_HELP_MAIN,"zobraz-odkazy")).
      ' </div>';
echo "</div>";
echo "</div>";
echo getFrmMoreHelp(get_help_url(AA_LINKS_HELP_MAIN,"seznamkat"));

echo $manager->getSearchbarEnd();   // close the searchbar form

echo GetPrintArray($r_err);
echo GetPrintArray($r_msg);
unset($r_err);
unset($r_msg);

echo $manager->getItems($link_zids);   // print links and actions
$r_state['manager'][$manager_id]    = $manager->getState();
// $r_state['manager_id'] = $manager_id;  // no special meaning - just manager id

// prints JavaScript which changes tree to current cat.
echo $tree->goCategory($r_state['cat_id'], 'patharea', 'document.filterform.cat_id', 'filterform');


HtmlPageEnd();
page_close();

