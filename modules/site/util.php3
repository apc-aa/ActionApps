<?php 
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

# Miscellaneous utility functions for "site" module

function AAPage($url=0, $add=0) {
  global $sess, $PHP_SELF,$r_spot_id;
  if (!$url)
    $url = con_url($PHP_SELF,"r_spot_id=$r_spot_id");
  return $sess->url( $add ? con_url($url , $add ) : $url );
}  

function ModW_HiddenRSpotId() {
	global $r_spot_id;
	print("<input type='hidden' name='r_spot_id' value='$r_spot_id'>");
}

function ModW_StoreTree( &$tree, $site_id ){
  global $db;
  
  $p_site_id = q_pack_id($site_id);
  
  $data = serialize( $tree );
  $SQL = "UPDATE site SET structure='$data' WHERE id='$p_site_id'";
  $db->query($SQL);
}  

function ModW_GetTree( &$tree, $site_id ){
  global $db;

  $p_site_id = q_pack_id($site_id);

  $SQL = "SELECT structure FROM site WHERE id='$p_site_id'";
  $db->query();
  if( $db->next_record() )
    $tree = unserialize( $db->f('structure') );
}  

function ModW_PrintSpotName($spot_id, $depth) {
  global $r_spot_id, $tree;
  $width = 10 * ($depth+1);
  $spotclass = ( $spot_id == $r_spot_id ) ? 'tabtit' : 'tabtxt';
  if( $tree->isSequenceStart($spot_id) ) {
    $add = @str_repeat('&nbsp;',$depth*2). '-';
  }
  
  if($vars = $tree->get('variables', $spot_id))
    $variables = "(". implode( $vars, ',' ) .")";

  if($conds = $tree->get('conditions', $spot_id)){
    reset($conds);
    $delim = ' ';
    while(list($k,$v) = each($conds)) {
      $conditions .= "$delim$k=$v";
      $delim = ', ';
    }
  }  
    
  echo "<table border=0 cellspacing=0 class=$spotclass width=200>
         <tr class=$spotclass>
          <td width=$width> &nbsp;$add</td>
          <td><a href=\"". AAPage( 0, "go_sid=". $spot_id). "\" class=$spotclass>".
           $tree->getName($spot_id)."</a>$conditions $variables</td>
         </tr>
        </table>";
}


function ModW_PrintVariables( $vars ) {
  global $sess, $PHP_SELF;
  echo "<tr><td valign=top><b>".L_SPOT_VARIABLES."</b></td><td>";
  if( isset($vars) AND is_array($vars) ){
    reset( $vars);
    while( list($k,$v) = each($vars) )
      echo "$v <span align=right><a href=\"". AAPage(0,"delvar=$k") ."\">".L_DELETE."</a></span><br>";
  }    
  echo "<form name=fvar action=\"$PHP_SELF\"><input type='text' name='addvar' value='' size='20' maxlength='50'><span align=right><a href='javascript:document.fvar.submit()'>".L_ADD."</a></span>";
  ModW_HiddenRSpotId();
  $sess->hidden_session();
  echo "</form></td></tr>";
}

function ModW_PrintConditions($conds, $vars) {
  global $sess, $PHP_SELF;
  echo "<tr><td valign=top><b>".L_SPOT_CONDITIONS."</b></td><td>";
  if( isset($vars) AND is_array($vars) ) {
    reset( $vars );
    $i=0;
    while( list($k, $v) = each($vars)) {
      if( $conds[$v] )
        echo "$v = $conds[$v] <span align=right><a href=\"". AAPage(0,"delcond=$v") ."\">".L_DELETE."</a></span><br>";
       else {
        echo "<form name=fcond$i action=\"$PHP_SELF\">$k = <input type='text' name='addcond' value='' size='20' maxlength='50'>
                <input type='hidden' name='addcondvar' value='$v'>
             <span align=right><a href='javascript:document.fcond$i.submit()'>".L_ADD."</a></span>";
        $sess->hidden_session();
	ModW_HiddenRSpotId();
        echo "</form>";
      }  
      $i++;
    }        
  }
  echo "</td></tr>";        
}

function ModW_ShowSpot(&$tree, $site_id, $spot_id) {
  global $db, $sess, $PHP_SELF;
  
  $SQL = " SELECT * FROM site_spot 
            WHERE site_id = '". q_pack_id($site_id). "'
              AND spot_id = '$spot_id'";
  $db->query($SQL);
  $content = safe($db->next_record() ? $db->f('content') : "");
  echo '<table align=left border=0 cellspacing=0 width="100%" class=tabtxt>';
  ModW_PrintVariables($tree->get('variables',$spot_id));
  if( ($vars=$tree->isOption($spot_id) ) )
    ModW_PrintConditions($tree->get('conditions',$spot_id), $vars);

  echo "<form method='post' name=fs action=\"$PHP_SELF\">";
  ModW_HiddenRSpotId();
  FrmInputText('name', L_SPOT_NAME, $tree->get('name', $spot_id), 50, 50, true, false, false, false);
  echo "<tr><td align=center colspan=2><textarea name='content' rows=20 cols=80>$content</textarea><br><br>
              <input type=submit name='". L_SUBMIT ."'>";
  $sess->hidden_session(); 
  echo "</td></tr>
      </form>
  </table>";
}
?>
