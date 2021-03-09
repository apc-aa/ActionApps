<?php
//$Id$
// Edit Category Page

// $cid category id should be send to the slice

// Edit Link Page

use AA\IO\DB\DB_AA;

require_once __DIR__."/../../include/init_page.php3";
require_once __DIR__."/../../include/formutil.php3";
require_once __DIR__."/../../modules/links/cattree.php3";
require_once __DIR__."/../../modules/links/constants.php3";
require_once __DIR__."/../../modules/links/util.php3";

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id  = $slice_id;
$links_info = GetModuleInfo($module_id,'Links');

// r_err and r_msg - passes messages between scripts
if ( !isset($r_err) ) {
    $sess->register('r_err');
    $sess->register('r_msg');
}

if ($cancel)
    go_url( StateUrl(self_base() . "index.php3"));

if ( !$cid ) {
    $cid = (int)$r_state['cat_id'];
}

$cpath = GetCategoryPath( $cid );
if ( IsCatPerm( PS_LINKS_EDIT_CATEGORY, $cpath ) ) {
    $r_state['cat_id']    = $cid;
    $r_state['cat_path']  = $cpath;
} else {
    MsgPage(StateUrl(self_base())."index.php3", _m('No permission to edit category'));
    exit;
}

if ( !$updated ) {
    // get this category info (r_category_id must be set (see init_page.php3))
    $dbarr = DB_AA::select1('', "SELECT * FROM links_categories", [['id', $r_state['cat_id'], 'i']]);

    if ($dbarr) {
        $cat_name       = $dbarr['name'];
        $cat_path       = $dbarr['path'];
        $description    = $dbarr['description'];
        $additional     = $dbarr['additional'];
        $note           = $dbarr['note'];
        $nolinks        = ($dbarr['nolinks']==1);
    }
}
$id = $r_state['cat_id'];

// AND now display the form --------------------------------------------------

// Print HTML start page (html begin, encoding, style sheet, no title)
HtmlPageBegin();
echo '<title>'. _m('ActionApps - Category Edit'). '</title>';

// find the lowest category we will need for admin interface
$tree_to_begin = ($links_info['select_start'] ?
                    min($links_info['select_start'], $links_info['tree_start']) :
                    $links_info['tree_start']);

$tree = new cattree($tree_to_begin, true, ' > ');

// count links in all subtree
$linkcounter = new linkcounter;
$links_count = $linkcounter->get_link_count($cpath, true);  // count and update

FrmJavascriptFile('javascript/js_lib.min.js');
FrmJavascriptFile('javascript/js_lib_links.min.js');   // js for category selection
$tree->printTreeData($tree_to_begin);

echo '
 <style>
  #body_white_color { color: #000000; }
 </style>
</head>
<body id="body_white_color">
 <H1><B>'. _m('Category Edit') .'</B></H1>';

PrintArray($r_err);
PrintArray($r_msg);

echo '<form name=f method=post action="catedit2.php3">';
    FrmTabCaption(_m('Category') . getFrmMoreHelp(get_help_url(AA_LINKS_HELP_LINK, "formular-kategorie"),
            ["before" => "(", "text" => "?", "after" => ")"]));
    FrmStaticText(_m('Id'), $id . '&nbsp; &nbsp; &nbsp;(' . _m('Links in subtree') . ': ' . $links_count . ')', "", "", false);
    FrmInputText( 'cat_name',     _m('Category name'),           $cat_name,  250, 50, false, "", get_help_url(AA_LINKS_HELP_CATEGORY,"nazev-kategorie") );
    FrmTextarea(  'description',  _m('Category description'),    $description, 3, 60, false, "", get_help_url(AA_LINKS_HELP_CATEGORY,"popis-kategorie"));
    FrmInputChBox('nolinks',      _m('No links'), $nolinks, false, '', 1, false, _m('Disalow storing of the links to this category?'));
    FrmTextarea(  'note',         _m('Editor\'s note'),    $note, 3, 60, false, "", get_help_url(AA_LINKS_HELP_CATEGORY,"poznamka-kategorie"));
    FrmHidden(    'additional',   $additional);
    FrmTabSeparator(_m('Subcategories') . getFrmMoreHelp(get_help_url(AA_LINKS_HELP_LINK, "podkategorie"),
            ["before" => "(", "text" => "?", "after" => ")"]));
echo '
      <tr>
        <td width=255 align=center valign="top"><b>'. _m('Category tree') .'</b><div class="tabhlp"><i>'. _m('select the category for crossreference') .'</i></div></td>
        <td width=60>&nbsp;</td>
        <td align=center valign="top"><b>'. _m('Selected subcategories') .'</b><div class="tabhlp"><i>'. _m('subcategories of this category') .'</i></div></td>
      </tr>
      <tr>
        <td colspan="3"><div id="patharea"> </div></td>
      </tr>
      <tr>
       <td align="CENTER" valign="TOP">'.
       $tree->getFrmTree('dblclick', $links_info['select_start'] ? $links_info['select_start'] : 2, 'patharea', '', false, '', 8, 'f') .'</td>
          <td><a href="javascript:MoveSelectedCat(\'document.f.tree\',\'document.f.selcat\')"><img src="'.AA_INSTAL_PATH.'images/right.gif" border="0" alt="select"></a></td>
          <td align="CENTER" valign="TOP">'.
       $tree->getFrmSubCatList(true, '', $cid, 250, 'selcat') .'</td>
      </tr>
      <tr>
       <td>&nbsp;</td>
       <td>&nbsp;</td>
       <td align="center">';
if ( IsCatPerm(PS_LINKS_ADD_SUBCATEGORY, $r_state['cat_path']) )
    echo ' <a href="javascript:NewCateg(\''._m('New subcategory').'\')">'. _m('Add') .'</a> &nbsp; ';
if ( IsCatPerm(PS_LINKS_DEL_SUBCATEGORY, $r_state['cat_path']) )
    echo ' <a href="javascript:DelCateg(\''._m('Remove selected subcategory?').'\')">'. _m('Del') .'</a> &nbsp; ';
    echo ' <a href="javascript:ChangeStateCateg(\'document.f.selcat\')">'. _m('Change state') .'</a>';

    FrmTabEnd( [
        'sbmt_button'  => [
            'type' =>"button",
                                             'value'=> ' '. _m('OK') .' ',
                                             'add'  => 'onClick="UpdateCategory(\'update_submit\')"'
        ],
                     'cancel',
                     'cid'          => ['type'=>"hidden", 'value'=> $cid],
                     'subcatIds'    => ['type'=>"hidden"],   // to this variable store assigned subcategory ids (by javascript)
                     'subcatNames'  => ['type'=>"hidden"],   // to this variable store assigned subcategory names (by javascript)
                     'subcatStates' => ['type'=>"hidden"]
    ]   // to this variable store assigned subcategory states (by javascript)
             );
echo '
    </form>
  </body>
</html>';


unset($r_err);
unset($r_msg);

page_close();
