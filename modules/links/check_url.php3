<?php
/** @param url         - url to check
 *  @param tree_start  - from which category you have to search
 *  @param checked_id  - link_id of checked link
 */
 //$Id$

$directory_depth = '../';
require_once "../../include/init_page.php3";
require_once $GLOBALS['AA_INC_PATH']."formutil.php3";
require_once $GLOBALS['AA_INC_PATH']."itemview.php3";
require_once $GLOBALS['AA_INC_PATH']."item.php3";
require_once "./util.php3";
require_once "./linksearch.php3";

function Links_getEditLink($lid) {
    return '&nbsp;<a href="javascript:edit('.$lid.')">'._m('Edit').'</a> ('._m('Rewrites link in bottom form').')';
}


// HTML page start
HtmlPageBegin();
echo '<title>'. _m('APC ActionApps - URL Check'). '</title>
<script language="javascript" type="text/javascript"><!--
  function edit(lid) {
    window.opener.location="'. $sess->url("linkedit.php3") .'&lid="+lid;
    window.close();
  }
//-->
</script>
</head>
<body>';


// find links with the same url
if( substr( $url, -1 ) == '/' )
  $url = substr( $url, 0, strlen($url)-1 );   # remove last '/'

$url = addslashes($url);

$conds = array( array( 'value'    => "$url OR ${url}_",
                       'operator' => 'XLIKE',
                       'url'      => 1 ),
                array( 'value'    => $checked_id,  // we do not want to find
                       'operator' => '<>',         // the link we are asking to
                       'id'       => 1 ));

$sort = '';

// we have to look for unassigned links (not assigned to some category),
// as well as for assinged ones

// 1 - base category - look for all links in the database (no matter in which subtree)
$start_cat_path = ($tree_start ? GetCategoryPath( $tree_start ) : 1);

$links_zids    = Links_QueryZIDs($start_cat_path, $conds, $sort, true, 'all');
$links_zids->add(Links_QueryZIDs($start_cat_path, $conds, $sort, true, 'unasigned'));

$format_strings = array ( "compact_top"   =>'<table border=0 cellspacing=0 cellpadding=5 bgcolor="'. COLOR_TABBG .'"><tr class=tabtit><td class=tabtit colspan=2>'. _m('URL') .': <b>_#L_URL___</b></td></tr>',
                          "odd_row_format"=>'<tr class=tabtxt><td class=tabtxt><a href="_#L_URL___" target="_blank">_#L_NAME__</a> ({switch({_#L_FOLDER})2:Zásobník:3:Koš:Aktivní}) <div class="tabsmall">_#L_DESCRI<br>({switch({_#L_CATIDS}).+:'._m('In category').'#: _#L_CATNAM:'._m('Link is not assigned to any category').'})</div></td><td class=tabtxt><a href="javascript:edit(\'_#LINK_ID_\')">'._m('Edit').'</a><div class=tabsmall>('._m('Rewrites link in bottom form').')</div></td></tr>',
                          "compact_bottom"=>'</table>'
                        );

// url nahore
// zarazeno v kategorii



if( $links_zids->count() != 0 ) {
    $itemview = new itemview($format_strings, '', GetLinkAliases(),
                          $links_zids, 0, 100, '', '', 'Links_GetLinkContent' );
    $itemview->print_view("NOCACHE");
} else {
    echo _m('Page with the specified Url is not in database, yet');
}


echo '  <center>
            <table>
              <tr>
                <td align="left" width="100%"><input type="button" value="'._m('Back').'" onclick="javascript:window.close()"></td>
              </tr>
            </table>
          </td></tr>
        </table>
        </center>
        </body>
        </html>';



page_close();
exit;
?>