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
/*
$Log$
Revision 1.2  2001/10/08 16:41:21  honzam
bugfix: no slices were displayed

Revision 1.1.1.1  2000/06/21 18:39:54  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:45  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.2  2000/06/12 21:40:56  madebeer
added $Id $Log and $Copyright to some stray files

*/
?>
<title><?php echo L_FEEDTO_TITLE ?></title>
</head><?php

echo '<body>
      <center>
      <h1>'. L_FEED_TO .'</h1>
      <form name=incf>
       <table border=0 cellspacing=0 cellpadding=0>
         <tr><td align=center>'. L_SLICE .'</td>
             <td width=60 align=center>'. L_FEED .'</td>
             <td width=60 align=center>'. L_ACTIVE_BIN .'</td></tr>'; 

$i=1;     // slice checkbox counter
$app=1;   // approved checkbox conter
if( is_array($g_slices) AND (count($g_slices) > 1) ) {
  reset($g_slices);
  while(list($k, $v) = each($g_slices)) { // you can feed only if you have autor or editor perms in destination slices
    if ( ((string)$slice_id != (string)$k) AND    
          CheckPerms( $auth->auth["uid"], "slice", $k, PS_EDIT_SELF_ITEMS) ) {
      echo '<tr><td>'. safe($v). '</td>
            <td align=center><input type=checkbox name=s'. $i++ .' value="'. $k .'"></td>';
      if( CheckPerms( $auth->auth["uid"], "slice", $k, PS_ITEMS2ACT) )
        echo '<td align=center><input type=checkbox name=a'. $app++ .' value="'. $k .'"></td>';
       else 
        echo '<td align=center>'. L_NO_PERMISSION_TO_FEED .'</td></tr>';
    }
  }
}
if( $i==1 )    // can't feed to any slice  
  echo '<tr><td colspan=3>'. L_NO_PERM_TO_FEED .'</td></tr>'; ?>

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
        window.opener.document.itemsform.action.value = 'feed';
        window.opener.document.itemsform.submit();                                     
        close();
      }
      // -->
      </SCRIPT>
      <input type=button name=sendfeeded value="<?php echo L_FEED ?>" onclick="SendFeed()">
      </center>
    </form>
  </body>
</html>
<?php page_close();?>
