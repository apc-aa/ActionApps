<?php
//$Id$
# Edit Category Page

// $cid category id should be send to the slice

# Edit Link Page
$directory_depth = '../';

require_once "../../include/init_page.php3";
require_once $GLOBALS[AA_INC_PATH]."formutil.php3";
require_once "./cattree.php3";
require_once "./util.php3";

// id of the editted module (id in long form (32-digit hexadecimal number))
$module_id = $slice_id;
$p_module_id = q_pack_id($module_id); # packed to 16-digit as stored in database
$links_info = GetModuleInfo($module_id,'Links');

// r_err and r_msg - passes messages between scripts
if ( !isset($r_err) ) {
    $sess->register('r_err');
    $sess->register('r_msg');
}

if($cancel)
    go_url( $sess->url(self_base() . "index.php3"));

if( !$cid )
    $cid = $r_state['cat_id'];

$cpath = GetCategoryPath( $cid );
if( IsCatPerm( PS_LINKS_EDIT_CATEGORY, $cpath ) ) {
    $r_state['cat_id']    = $cid;
    $r_state['cat_path']  = $cpath;
}
else {
    MsgPage($sess->url(self_base())."index.php3", _m('No permission to edit category'));
    exit;
}

if( !$updated ) {
    # get this category info (r_category_id must be set (see init_page.php3))
    $SQL= " SELECT * FROM links_categories WHERE id=".$r_state['cat_id'];
    $db->query($SQL);
    if ($db->next_record()) {
        $cat_name       = $db->f('name');
        $html_template  = $db->f('html_template');
        $inc_file1      = $db->f('inc_file1');
        $inc_file2      = $db->f('inc_file2');
        $banner_file    = $db->f('banner_file');
        $cat_path       = $db->f('path');
        $description    = $db->f('description');
        $note           = $db->f('note');
    }
}
$id = $r_state['cat_id'];

# count links in all subtree
$links_count = CountCategLinks($cat_path, $r_state['cat_id']);


// AND now display the form --------------------------------------------------

// Print HTML start page (html begin, encoding, style sheet, no title)
HtmlPageBegin();
echo '<title>'. _m('APC ActionApps - Category Edit'). '</title>';

$tree = new cattree( $db, $links_info['tree_start'], true, ' > ');
// special javascript for category selection
echo '<script language="JavaScript" type="text/javascript"
      src="'.$GLOBALS['AA_INSTAL_PATH'].'javascript/js_lib_links.js"></script>';
$tree->printTreeData($links_info['tree_start']);

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
    FrmTabCaption( _m('Category') );
    FrmStaticText(                _m('Id'),             $id. '&nbsp; &nbsp; &nbsp;('. _m('Links in subtree').': '.$links_count.')', false, "", "", false);
    FrmInputText( 'cat_name',     _m('Category name'),           $cat_name,  250, 50, false);
    FrmTextarea(  'description',  _m('Category description'),    $description, 3, 60, false);
    FrmTextarea(  'note',         _m('Editor\'s note'),    $note, 3, 60, false);

//    FrmHidden( 'html_template',$html_template);
//    FrmHidden( 'inc_file1',    $inc_file1);
//    FrmHidden( 'inc_file2',    $inc_file2);
//    FrmHidden( 'banner_file',  $banner_file);

//    FrmInputText( 'html_template',_m('HTML template'),  $html_template,  250, 50, false);
//    FrmInputText( 'inc_file1',    _m('First text box'), $inc_file1,  250, 50, false);
//    FrmInputText( 'inc_file2',    _m('Second text box'),$inc_file2,  250, 50, false);
//    FrmInputText( 'banner_file',  _m('Banner'),         $banner_file,  250, 50, false);
    FrmTabSeparator( _m('Subcategories') );
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
       $tree->getFrmTree(false, 'dblclick', $links_info['select_start'] ? $links_info['select_start'] : 2,
                                  'patharea', '', false, '', 8, 'f') .'</td>
          <td><a href="javascript:MoveSelectedCat(\'document.f.tree\',\'document.f.selcat\')"><img src="'.$AA_INSTAL_PATH.'images/right.gif" border="0" alt="select"></a></td>
          <td align="CENTER" valign="TOP">'.
       $tree->getFrmSubCatList(true, '', $cid, 250, 'selcat') .'</td>
      </tr>
      <tr>
       <td>&nbsp;</td>
       <td>&nbsp;</td>
       <td align="center">';
//         <a href="javascript:MoveSelectedUp(\'document.f.selcat\')"><img src="'.$AA_INSTAL_PATH.'images/cup.gif" border="0" alt="'. _m('Up') .'"></a>
//         <a href="javascript:MoveSelectedDown(\'document.f.selcat\')"><img src="'.$AA_INSTAL_PATH.'images/cdown.gif" border="0" alt="'. _m('Down') .'"></a><br>';
if( IsCatPerm(PS_LINKS_ADD_SUBCATEGORY, $r_state['cat_path']) )
    echo ' <a href="javascript:NewCateg(\''._m('New subcategory').'\')">'. _m('Add') .'</a> &nbsp; ';
if( IsCatPerm(PS_LINKS_DEL_SUBCATEGORY, $r_state['cat_path']) )
    echo ' <a href="javascript:DelCateg(\''._m('Remove selected subcategory?').'\')">'. _m('Del') .'</a> &nbsp; ';
    echo ' <a href="javascript:ChangeStateCateg(\'document.f.selcat\')">'. _m('Change state') .'</a>';

    FrmTabEnd( array('sbmt_button'  => array('type' =>"button",
                                             'value'=> ' '. _m('OK') .' ',
                                             'add'  => 'onClick="UpdateCategory(\'update_submit\')"'),
                     'cancel',
                     'cid'          => array('type'=>"hidden", 'value'=> $cid),
                     'subcatIds'    => array('type'=>"hidden"),   // to this variable store assigned subcategory ids (by javascript)
                     'subcatNames'  => array('type'=>"hidden"),   // to this variable store assigned subcategory names (by javascript)
                     'subcatStates' => array('type'=>"hidden")),   // to this variable store assigned subcategory states (by javascript)
               $sess);                    // add session_id
echo '
    </form>
  </body>
</html>';


unset($r_err);
unset($r_msg);

page_close();
?>