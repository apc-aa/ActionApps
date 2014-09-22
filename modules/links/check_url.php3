<?php
/** @param url         - url to check
 *  @param tree_start  - from which category you have to search
 *  @param checked_id  - link_id of checked link
 */
 //$Id$

require_once "../../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."itemview.php3";
require_once AA_INC_PATH."item.php3";
require_once AA_BASE_PATH."modules/links/util.php3";
require_once AA_BASE_PATH."modules/links/linksearch.php3";

// HTML page start
HtmlPageBegin();
echo '<title>'. _m('ActionApps - URL Check'). '</title>';
$js = 'function edit(lid) {
           window.opener.location="'. $sess->url("linkedit.php3") .'&lid="+lid;
           window.close();
       }
      ';
FrmJavascript($js);
echo '
 </head>
<body>';

$format_strings = array( "compact_top"   =>'<table border=0 cellspacing=0 cellpadding=5 bgcolor="'. COLOR_TABBG .'"><tr class=tabtit><td class=tabtit colspan=2>'. _m('URL') .': <b>_#L_URL___</b></td></tr>',
                          "odd_row_format"=>'<tr class=tabtxt><td class=tabtxt><a href="_#L_URL___" target="_blank">_#L_NAME__</a> ({switch({_#L_FOLDER})2:Zásobník:3:Koš:Aktivní}) <div class="tabsmall">_#L_DESCRI<br>({switch({_#L_CATIDS}).+:'._m('In category').'#: _#L_CATNAM:'._m('Link is not assigned to any category').'})</div></td><td class=tabtxt><a href="javascript:edit(\'_#LINK_ID_\')">'._m('Edit').'</a><div class=tabsmall>('._m('Rewrites link in bottom form').')</div></td></tr>',
                          "compact_bottom"=>'</table>'
                       );

$out = Links_getUrlReport($url, $format_strings, $checked_id, $tree_start);
echo ($out ? $out : _m('Page with the specified Url is not in database, yet'));

echo '
    <div align="center">
      <input type="button" value="'._m('Back').'" onclick="window.close()">
    </div>
  </body>
</html>';

page_close();
exit;
?>