<?php /* ldap.php3 */
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

function init() {
  $ds = ldap_connect("lide.seznam.cz");
  if($ds)
//    ldap_bind($ds,"cn=root, o=Lerdorf, c=CA","banana");
    ldap_bind($ds);
   else
    echo "Unable to connect";
  return $ds;
}

function ou_dropdown($ds) {
  global $ou; 
  $result = ldap_list($ds, "dc=ecn,dc=apc,dc=org","objectclass=*",array("sn")); 
  if($result) {
    $ous = ldap_get_entries($ds,$result);
    if($ou) 
      echo "<option>$ou\n";
    if($ou!="Any")
      echo "<option>Any\n";
    for ($i=0; $i<$ous["count"]; $i++) {
      if($ou!=$ous[$i]["ou"][0])
        echo "<option>".$ous[$i]["ou"][0]."\n";
    }
    ldap_free_result($result); 
  } else 
    echo "Unable to read entry";
}
    
function letters() {  ?>
  <input type="submit" name="let" value="*"> <?
  for($i=ord('A'); $i<=ord('Z'); $i++) { ?>
    <input type="submit" name="let" value="<?echo chr($i)?>">  <?
  }
}

function show_entries($array,$full=0) {
  for ($i=0; $i<$array["count"]; $i++) {
    while(list($key,$value) = each($array[$i])) {
      if(is_array($value)) {
        for($j=0; $j < $value[count]; $j++) {
          if($full) {
            echo "<tr bgcolor=#3c7dbf>";
            echo "<th align=right>$key:</th>";
            echo "<td>&nbsp;".$value[$j]."</td></tr>\n";
          } else {
            if($key=="cn") { 
              echo "<tr bgcolor=#3c7dbf>";
              echo "<th align=right>Name:</th>";
              echo "<td>&nbsp;".$value[$j]."</td></tr>\n";
            } else
              if($key=="mail") {
                echo "<tr bgcolor=#3c7dbf>";
                echo "<th align=right>Email:</th>";
                echo "<td>&nbsp;".$value[$j]."</td></tr>\n";
              }
          }
        } 
      } else {
        if($key != "count" && !is_int($key)) {
          if($full) {
            echo "<tr bgcolor=#3c7dbf>";
            echo "<th align=right>$key:</th>";
            echo "<td>&nbsp;".$value."</td></tr>\n"; 
          } 
        }
      }
    }
    echo "<tr><td colspan=2><hr>\n</td></tr>";
  }
}

/*
$Log$
Revision 1.1  2000/06/21 18:40:40  madebeer
Initial revision

Revision 1.1.1.1  2000/06/12 21:50:24  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.2  2000/06/12 21:41:24  madebeer
removed whitespace from config-ecn.inc
added $Id $Log and $Copyright to some stray files

*/
?>
<html>
<head>
<title>Address Book</title>
<STYLE>    A {text-decoration: none;}
    INPUT {font-family: Geneva, Verdana, Helvetica, Arial;font-size: 10pt;}
</STYLE>
</head>
<body bgcolor="#ffffff" text="#000000" link="#ffffff" vlink="#ffffff">

