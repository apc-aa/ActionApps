<?php  #form for feeding selected items
//$Id$
/* 
Copyright (C) 1999, 2000 Association for Progressive Communications 
http://www.apc.org/

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

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Export Item to Selected Slice") ?></title>
</head><?php

echo '<body>
      <center>
      <h1>'. _m("Export selected items to selected slice") .'</h1>
      <form name=incf>
       <table border=0 cellspacing=0 cellpadding=0>
         <tr><td align=center>'. _m("Slice") .'</td>
             <td width=60 align=center>'. _m("Export") .'</td>
             <td width=60 align=center>'. _m("Active") .'</td></tr>'; 

$i=1;     // slice checkbox counter
$app=1;   // approved checkbox conter
if( is_array($g_modules) AND (count($g_modules) > 1) ) {
  reset($g_modules);
  while(list($k, $v) = each($g_modules)) { # you can feed only if you have autor or editor perms in destination slices
    if( $v['type'] != 'S' )                
      continue;                            # we can feed just between slices ('S')
    if( ((string)$slice_id != (string)$k) AND    
          CheckPerms( $auth->auth["uid"], "slice", $k, PS_EDIT_SELF_ITEMS) ) {
      echo '<tr><td>'. safe($v['name']). '</td>
            <td align=center><input type=checkbox name=s'. $i++ .' value="'. $k .'"></td>';
      if( CheckPerms( $auth->auth["uid"], "slice", $k, PS_ITEMS2ACT) )
        echo '<td align=center><input type=checkbox name=a'. $app++ .' value="'. $k .'"></td>';
       else 
        echo '<td align=center>'. _m("No permission") .'</td></tr>';
    }
  }
}
if( $i==1 )    // can't feed to any slice  
  echo '<tr><td colspan=3>'. _m("No permission to set feeding for any slice") .'</td></tr>'; ?>

      </table>
      <SCRIPT Language="JavaScript"><!--  // do not move this script up - it uses php3 variables
      function SendFeed(){
        var chboxname;
        var delimeter='';
        for( var i=1; i< <?php echo $i?>; i++ ) {
          chboxname = 'document.incf.s' + i;
          if( eval(chboxname).checked ) {
            window.opener.document.itemsform.feed2slice.value += delimeter + eval(chboxname).value;
            delimeter=',';
          }  
        }
        delimeter='';
        for( var j=1; j< <?php echo $app?>; j++ ) {
          chboxname = 'document.incf.a' + j;
          if( eval(chboxname).checked ) {
            window.opener.document.itemsform.feed2app.value += delimeter + eval(chboxname).value;
            delimeter=',';
          }  
        }
        window.opener.document.itemsform.akce.value = 'feed';
        window.opener.document.itemsform.submit();                                     
        close();
      }
      // -->
      </SCRIPT>
      <input type=button name=sendfeeded value="<?php echo _m("Export") ?>" onclick="SendFeed()">
      </center>
    </form>
<?php 
"</body></html>";
page_close();?>
