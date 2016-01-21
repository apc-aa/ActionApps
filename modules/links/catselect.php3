<?php
//$Id$
// Allows to select category

// $cid - current category which should be shown
// $tree_start - root categoruy for the tree

// Edit Link Page

require_once "../../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_BASE_PATH."modules/links/cattree.php3";
require_once AA_BASE_PATH."modules/links/util.php3";

if (!$cid) {
    $cid = $r_state['cat_id'];
}

$cpath = GetCategoryPath( $cid );

// AND now display the form --------------------------------------------------

// Print HTML start page (html begin, encoding, style sheet, no title)
HtmlPageBegin();
echo '<title>'. _m('ActionApps - Select Category'). '</title>';

$tree = new cattree($tree_start, true, ' > ');
FrmJavascriptFile('javascript/js_lib.js');
FrmJavascriptFile('javascript/js_lib_links.js');   // js for category selection
$tree->printTreeData($links_info['tree_start']);

if ( !$cid ) {  // default category defined
    $on_load = 'onLoad="GoToCategoryID(\''.$cid.'\', eval(document.f.tree), \'patharea\', \'\')"';
}

echo '
 <style>
  #body_white_color { color: #000000; }
 </style>
</head>
<body id="body_white_color" "'.$on_load.'">
  <form name="f" method=post>';

    FrmTabCaption( _m('Select Category') );
echo '
      <tr>
       <td colspan="2"><div id="patharea">&nbsp;</div></td>
      <tr>
       <td colspan="2" align="CENTER" valign="TOP">'.
         $tree->getFrmTree(false, 'change', $tree_start, 'patharea', '', false) .
       '</td>
      </tr>
      ';

    FrmTabEnd( array('sbmt_button'  => array('type' =>"button",
                                             'value'=> ' '. _m('OK') .' ',
                                             'add'  => 'onClick="UpdateCategory(\'update_submit\')"'),
                     'cancel'       => array('add'  => 'onClick="window.close()"')));
echo '
    </form>
  </body>
</html>';

page_close();
?>
