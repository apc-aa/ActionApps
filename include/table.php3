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

#
#	Ctable - class for storing tables
#
define("TABLE_PHP3_INC",1);

class Ctable {    
  var $scroll;              #scroller for this table      
  var $scrollname;          #the name of the scroll
	var $dbcols;              #array of database columns
	var $actioncols;          #array of database columns
  var $title;               #table name
  var $db;                  #database
  var $selfurl;             #form action url
  var $categories;          #used for category dbcols - array of allowed categories

  var $table_bg_color;
  var $hl_color;
  var $icon;
  
  function Ctable($scr, $scrn, $tit, $db, $url, $SQL, $cat="") {
    $this->scroll     = $scr;
    $this->scrollname = $scrn; 
    $this->title      = $tit;      
    $this->db         = $db;         
    $this->selfurl    = $url;
    $this->SQLquery   = $SQL;
    $this->categories = $cat;      
  }  
  
  function addDbCol($name, $head, $size, $width="", $type="text") {
		$this->dbcols[$name][head]  = $head;
		$this->dbcols[$name][width] = $width;
		$this->dbcols[$name][type]  = $type;
		$this->dbcols[$name][size]  = $size;
  }  

  function addActionCol($name, $href, $img, $alt="", $id="", $type="clear", $a_tag_add="") {
		$this->actioncols[$name][href]  = $href;
		$this->actioncols[$name][img]   = $img;
		$this->actioncols[$name][alt]   = $alt;
		$this->actioncols[$name][type]  = $type;   // if type is addId, href is completed from database 
                                               // - column is specified by id atribute
		$this->actioncols[$name][id]    = $id;
		$this->actioncols[$name][aadd]  = $a_tag_add;  // adds this variable to <a .. tag (target = ...)
  }  
  
  function setLook($bg_color, $hl_color, $icon) {
    $this->table_bg_color = $bg_color;
    $this->hl_color = $hl_color;
    $this->icon = $icon;
  }  
  
  function cols() {
    return (count($this->dbcols) +  count($this->actioncols));
  }
    
  function printTable() { 
    echo '<table width="80%" border="1" cellspacing="0" cellpadding="0" align="CENTER" bgcolor="'. $this->table_bg_color. '">';
    echo '<tr><td align="CENTER" valign="MIDDLE" bgcolor="'. $this->hl_color .'" colspan='. $this->cols(). '>';
    echo '<img src="'. $this->icon .'" width=36 height=36 border=0 alt="" align="left">';
    
    echo '<font size="+2" color="'. $this->table_bg_color. '"><b>'. $this->title . 
         ' (<i>'. $this->scroll->itmcnt . '</i>)</b></font>';
    $this->scroll->pVisButton();
	  echo '</td></tr>';
    
    if($this->scroll->visible) { 
    	if($this->scroll->pgcnt > 1) { # navbar
        echo '<tr><td class=scroller align=center colspan='. $this->cols() .'>';
        $this->scroll->pnavbar();
        echo "</td>\n</tr>";
      } 
      echo '<tr>';
  		while(list($name, $col) = each($this->dbcols)) {     #database columns
        if( $col[width] )
          echo '<td class=scrhead width="'. $col[width] .'">';
         else 
          echo '<td class=scrhead><b>';
        $this->scroll->pSort($name, $col[head]);
        echo "</b></td>\n";
      }  
      if(count($this->actioncols)>0)
        echo '<td colspan='. count($this->actioncols) .' class=scrhead width=10%>'. L_ACTION .":</b></td>\n";
      echo '</tr>';  
      if($this->scroll->pgcnt > 0) {
        $this->db->query($this->SQLquery);
        $this->db->seek($this->scroll->metapage * ($this->scroll->current - 1));
        $i = 1;
        while($this->db->next_record()) { 
      		echo '<tr>';
          if( date2sec($this->db->f(publish_date)) > date2sec(date("Y-m-d"). " 23:59:59") )
            $i_state = "N";
           else if( date2sec($this->db->f(expiry_date)) <= date2sec(date("Y-m-d"). " 0:0:0"))
            $i_state = "E";
           else 
            $i_state = "P";
          reset($this->dbcols);
          while(list($name, $col) = each($this->dbcols)) {     #database columns
            switch( $col[type] ) {
              case "date":
                echo '<td nowrap>';
                switch($i_state) {
                  case "N": echo '<img src="../images/notpubl.gif" align="right" border=0 alt="'. L_NOT_PUBLISHED .'">'; break;
                  case "E": echo '<img src="../images/expired.gif" align="right" border=0 alt="'. L_EXPIRED .'">'; break;
                  case "P": echo '<img src="../images/publish.gif" align="right" border=0 alt="'. L_PUBLISHED .'">'; break;
                }  
                echo htmlspecialchars(datetime2date($this->db->f($name))) .'</td>';
                break;
              case "category":
                $foo = ( $this->db->f($name)=="" ? '&nbsp;' : htmlspecialchars($this->db->f($name)));
                if( $this->categories[$this->db->f($name)]=="" )
                  echo "<td class=taberr>$foo</td>";
                 else 
                  echo "<td>$foo</td>";
                break;
              case "published":   // specific type for index.php3 tables - headline of expired items
                switch($i_state) {
                  case "N": echo '<td class=notpubl>'; break;
                  case "E": echo '<td class=expired>'; break;
                  case "P": echo '<td>'; break;
                }  
                if( $this->db->f(highlight) )
                  echo '<IMG src="../images/hlight.gif" width=16 height=20 border=0 align="right" alt="'. L_HIGHLIGHTED .'">';
                 else
                  echo '<img src="../images/pixel_blank.gif" width=16 height=1 border=0 align="right" alt="">';
                if( $this->db->f(id)!=$this->db->f(master_id) )
                  echo '<IMG src="../images/feed.gif" width=20 height=20 border=0 align="right" alt="'. L_FEEDED .'">';
                echo "&nbsp;". htmlspecialchars($this->db->f($name)) .'</td>';  
                break;
              default:
                echo '<td>'. htmlspecialchars($this->db->f($name)) .'</td>';
                break;
            }
          }
          reset($this->actioncols);
          while(list($name, $col) = each($this->actioncols)) {     #database columns
            switch( $col[type] ) {
              case "addId":
                echo '<td><a href="'. $col[href] . unpack_id($this->db->f($col[id])) .'"' .$col[aadd]. '><image alt="'. $col[alt] .'" src="'. $col[img] .'" border=0></a></td>';
                break;
              default:  
                echo '<td><a href="'. $col[href] .'"' .$col[aadd]. '><image alt="'. $col[alt] .'" src="'. $col[img] .'" border=0></a></td>';
                break;
            }
          }
          echo '</tr>';
       	  if($i++ > $this->scroll->metapage)
            break; 
        }
      }
      echo '<tr>';
      reset($this->dbcols);
   		while(list($name, $col) = each($this->dbcols)) {     #database columns
        echo '<form method=post action="'. $this->selfurl .'">';
        echo '<td><input type=text size='. $col[size] .' name="flt_'. $this->scrollname .'_'. $name 
             .'_val" value="'. htmlspecialchars(dequote($this->scroll->filters[$name][value])) 
             .'"><INPUT type="image" border=0 src="../images/rsmall.gif" width=0 height=0></td></form>';
      }
      echo '<td colspan='. count($this->actioncols) .">&nbsp;</td>\n";
      echo '</tr>';
    	if($this->scroll->pgcnt > 1) { # navbar
        echo '<tr><td class=scroller align=center colspan='. $this->cols(). '>';
        $this->scroll->pnavbar();
        echo "</td>\n</tr>";
      }
    } 
    echo '</table>';
  }  
} 
/*
$Log$
Revision 1.1  2000/06/21 18:40:48  madebeer
Initial revision

Revision 1.1.1.1  2000/06/12 21:50:27  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.5  2000/06/12 21:41:24  madebeer
removed whitespace from config-ecn.inc
added $Id $Log and $Copyright to some stray files

*/
?>
