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
                          "odd_row_format"=>'<tr class=tabtxt><td class=tabtxt><a href="_#L_URL___" target="_blank">_#L_NAME__</a><div class="tabsmall">_#L_DESCRI<br>({switch({_#L_CATNAM}).+:'._m('In category').'#: _#L_CATNAM:'._m('Link is not assigned to any category').'})</div></td><td class=tabtxt><a href="javascript:edit(\'_#LINK_ID_\')">'._m('Edit').'</a><div class=tabsmall>('._m('Rewrites link in bottom form').')</div></td></tr>',
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


if( isset($found_ids) and is_array($found_ids) ) {
    // lookup - all categories names
    $SQL= " SELECT id, name FROM links_categories WHERE deleted='n'";
    $db->query($SQL);
    while ($db->next_record())
        $catTranslate[$db->f('id')] = htmlspecialchars($db->f('name'));
    reset($found_ids);

    while (list(,$found_lid) = each($found_ids)) {
        # find the most appropriate finding
        $SQL = " SELECT links_links.id AS id, links_links.url AS url, links_links.name AS name,
                links_link_cat.base AS base, links_links.description AS description,
                links_links.rate AS rate, links_categories.path AS path
                 FROM links_link_cat,links_links,links_categories
                 WHERE links_link_cat.what_id = links_links.id
                   AND links_link_cat.category_id = links_categories.id
                   AND links_links.id='$found_lid'
                   ORDER BY base DESC, priority DESC, rate DESC";

        $db->tquery($SQL);
        if ($db->next_record()) {
            $linkPathName[] = NamePath(2,$db->f('path'),$catTranslate," &gt; ",
                con_url($sess->url("../TODO.php3"), "ctg="), "whole", "_blank");

            $descript = '<p>
                <a href="'.$db->f('url').'">'.$db->f('name').( ($db->f('base') =='y') ? "" : "&nbsp;@").'</a><br>'.
                $db->f('description').'<br>'.
                _m('URL') .': <a href="'.$db->f('url').'" target="_blank">'.$db->f('url').'</a><br>'.
                _m('Rate') .': '. $db->f('rate') .'<br>';

            if ( isset($linkPathName) AND is_array($linkPathName) ) {
                $descript .= _m('In category') .':<br>';
                reset($linkPathName);
                while ( list(,$v) = each($linkPathName) ) {
                    $descript .= '&nbsp;&nbsp;&nbsp;&nbsp;'.$v.'<br>';
                }
            }
            $descript .= '</p>';

            $message .= _m('Page is already in database'). Links_getEditLink($found_lid)."<BR>\n";
        } else {
            $message .= _m('Page is already in database, but it is not displayed in any category, now'). Links_getEditLink($found_lid). "<BR>\n";;
            $descript = "";
        }
    }
} else {
    $message = _m('Page with the specified Url is not in database, yet'). "<BR>\n";
    $descript = "";
}

// Display the page -----------------------------------------------------------

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
<body>
<center>
<table bgcolor="'. COLOR_TABBG .'">
  <tr><td align="center" class="tabtxt">URL = '.$url .'</td></tr>
  <tr><td align="center" class="tabtxt">'. $message .'</td></tr>
  <tr><td>&nbsp;</td></tr>
  <tr><td align="left" class="tabtxt">'. $descript .'</td></tr>
  <tr><td>&nbsp;</td></tr>
  <tr><td align="center">
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
?>