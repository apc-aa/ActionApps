<?php  
//$Id$

$directory_depth = '../';
require_once "../../include/init_page.php3";
require_once $GLOBALS['AA_INC_PATH']."formutil.php3";
require_once "./util.php3";

function Links_getEditLink($lid) {
    return '&nbsp;<a href="javascript:edit('.$lid.')">'._m('Edit in bottom window').'</a>';
}    
    

if( substr( $url, -1 ) == '/' )
  $url = substr( $url, 0, strlen($url)-1 );   # remove last '/'

// we do not want to find the link we are asking to
if( $checked_id )
    $not_current = "AND (id <> '$checked_id')";
  

$SQL = " SELECT id, url FROM links_links 
           WHERE (url='$url' OR url LIKE '$url". "_') $not_current";
$db->tquery($SQL);
while ($db->next_record()) {
    $found_ids[] = $db->f('id');
}    

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