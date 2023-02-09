<?php
/** @param url         - url to check
 *  @param tree_start  - from which category you have to search
 *  @param checked_id  - link_id of checked link
 */
 //$Id$

const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/itemview.php";
require_once __DIR__ . "/../../include/item.php";
require_once __DIR__ . "/../../modules/links/util.php";
require_once __DIR__ . "/../../modules/links/linksearch.php";

// HTML page start
HtmlPageBegin();
echo '<title>'. _m('ActionApps - URL Check'). '</title>';
$js = 'function edit(lid) {
           window.opener.location="'. StateUrl("linkedit.php") .'&lid="+lid;
           window.close();
       }
      ';
FrmJavascript($js);
echo '
 </head>
<body>';

$format_strings = [
    "compact_top"   =>'<table class="aa-table"><tr class="aa-tbl-header"><th colspan=2>'. _m('URL') .': _#L_URL___</th></tr>',
                          "odd_row_format"=>'<tr><td><a href="_#L_URL___" target="_blank">_#L_NAME__</a> ({switch({_#L_FOLDER})2:Zásobník:3:Koš:Aktivní}) <div class="tabsmall">_#L_DESCRI<br>({switch({_#L_CATIDS}).+:'._m('In category').'#: _#L_CATNAM:'._m('Link is not assigned to any category').'})</div></td><td><a href="javascript:edit(\'_#LINK_ID_\')">'._m('Edit').'</a><div class=tabsmall>('._m('Rewrites link in bottom form').')</div></td></tr>',
                          "compact_bottom"=>'</table>'
];

$out = Links_getUrlReport($url, $format_strings, $checked_id, $tree_start);
echo ($out ?: _m('Page with the specified Url is not in database, yet'));

echo '
    <div>
      <input type="button" value="'._m('Back').'" onclick="window.close()">
    </div>
  </body>
</html>';

page_close();
exit;
