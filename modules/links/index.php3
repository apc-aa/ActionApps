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

// used in init_page.php3 script to include config.php3 from the right directory

require_once __DIR__."/../../include/init_page.php3";
require_once __DIR__."/../../include/varset.php3";
require_once __DIR__."/../../include/formutil.php3";
require_once __DIR__."/../../include/itemview.php3";
require_once __DIR__."/../../include/item.php3";

require_once __DIR__."/../../modules/links/linksearch.php3";
require_once __DIR__."/../../modules/links/constants.php3";
require_once __DIR__."/../../modules/links/actions.php3";
require_once __DIR__."/../../modules/links/cattree.php3";
require_once __DIR__."/../../modules/links/util.php3";      // module specific utils


// Check permissions for this page.
// You should change PS_MODP_EDIT_POLLS permission to match the permission in your
// module. See /include/perm_core.php3 for more details

if ( !IfSlPerm(PS_LINKS_EDIT_LINKS) ) {
    MsgPage(StateUrl(self_base())."index.php3", _m("No permissions to edit links"));
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
$module_id   = $slice_id;
$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database
$links_info  = GetModuleInfo($module_id,'Links');

$actions   = new AA_Manageractions;
$actions->addAction(new AA_Manageraction_Links_Move('Activate', 1));
$actions->addAction(new AA_Manageraction_Links_Move('Folder2',  2));
$actions->addAction(new AA_Manageraction_Links_Move('Folder3',  3));
$actions->addAction(new AA_Manageraction_Links_Check('Check'));
$actions->addAction(new AA_Manageraction_Links_Highlight('Highlight',true));
$actions->addAction(new AA_Manageraction_Links_Highlight('DeHighlight', false));
$actions->addAction(new AA_Manageraction_Links_Delete('Delete'));
$actions->addAction(new AA_Manageraction_Links_Add2Cat('Add2Cat',   StateUrl("getcat.php3?start=".GetCategoryFromPath($links_info['tree_start']))));
$actions->addAction(new AA_Manageraction_Links_Move2Cat('Move2Cat', StateUrl("getcat.php3?start=".GetCategoryFromPath($links_info['tree_start']))));


$switches  = new AA_Manageractions;
$switches->addAction(new AA_Manageraction_Links_DeleteTrash('DeleteTrash'));
$switches->addAction(new AA_Manageraction_Links_GoCateg('GoCateg'));
$switches->addAction(new AA_Manageraction_Links_Tab('Tab'));
$switches->addAction(new AA_Manageraction_Links_GoBookmark('GoBookmark'));


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
         'listlen'              => ($listlen ? $listlen : EDIT_ITEM_COUNT),
         'slice_id'             => $slice_id
     ],
     'itemview'  => [
         'manager_vid'          => $links_info['manager_vid'],      // id of view which controls the design
         'format'               => [                           // optionaly to manager_vid you can set format array
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
             'id'               => $link_info['id']
         ],
         'fields'               => '',
         'aliases'              => GetLinkAliases(),
         'get_content_funct'    => 'Links_GetLinkContent'
     ],
     'actions_hint' => _m("Perform action on selected items"),
     'actions_hint_url' => get_help_url(AA_LINKS_HELP_MAIN,"co-udelat-sodkazy"),
     'actions'   => $actions,
     'switches'  => $switches,
     'messages'  => [
         'title'            => _m('ActionApps - Links Manager')
     ]
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

$manager = new AA_Manager($manager_id, $manager_settings);
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
      document.location = "'.StateUrl('catedit.php3').'&cid=" + document.filterform.cat_id.value;
  }
</script>
</head>';

// This is not definitive place for Bookmarks definition. It will be in database
// (probably in User profiles)
$bookmarks[1] = _m('All my links');
$bookmarks[2] = _m('Links to check');
$bookmarks[3] = _m('Last edited');

showMenu("linkmanager", $r_state['bin'], $navbar != "0", $leftbar != "0");

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
echo getFrmInputButtons([
    'gocat' => [
        'type' => 'button',
        'value' => _m('Show Links'),
        'add' => 'onclick="document.filterform.submit()"',
        'help' => get_help_url(AA_LINKS_HELP_MAIN, "zobraz-odkazy")
    ],
    'editcat' => [
        'type' => 'button',
        'value' => _m('Edit Category'),
        'add' => 'onclick="EditCurrentCat()"',
        'help' => get_help_url(AA_LINKS_HELP_MAIN, "editujkat")
    ]
//                        'bookmark' => array('type'=>'button',
//                                            'value'=>_m('Bookmark'))
], 'bottom', true, COLOR_TABBG);

echo '<tr><td class=tabtxt>';
FrmChBoxEasy("show_subtree", $r_state["show_subtree"]);
echo _m('Show subtree links');
echo getFrmMoreHelp(get_help_url(AA_LINKS_HELP_MAIN,"zobraz-odkazy")). ' </td></tr>';

echo "</table></td></tr>";
echo "<tr><td>".getFrmMoreHelp(get_help_url(AA_LINKS_HELP_MAIN,"seznamkat"))."</td></tr>";
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

