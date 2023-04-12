<?php
 //$Id$

// const  AA_REGISTER_GLOBALS = true;
use AA\Util\InputVars;

require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/itemview.php";
require_once __DIR__ . "/../../include/item.php";
require_once __DIR__ . "/../../modules/links/util.php";
require_once __DIR__ . "/../../modules/links/linksearch.php";

try {
    /**  @var string checked_id  link_id of checked link */
    $checked_id = InputVars::intGET('checked_id', true);

    /**  @var string tree_start  from which category you have to search */
    $tree_start = InputVars::intGET('tree_start', true);

    /** @var string $url url to check */
    $url = InputVars::stringGET('url', true);
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

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
