<SCRIPT language="php">
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


$num_fail = $num_pass = 0; 

function ptest( $boolean, $label) {
  global $num_fail, $num_pass;
  define("Pass",'Pass');  define("Fail",'Error');

  echo "testing: [$label] ....";
  echo $boolean ? Pass : Fail;
  echo "\n";

  $boolean ? $num_pass++ : $num_fail++;
}

function p_array($haystack)
{
  for($i=0;$i<count($haystack);$i++)
    echo $haystack[$i];

  echo "\n";
}

/*
$Log$
Revision 1.1  2000/06/21 18:40:28  madebeer
Initial revision

Revision 1.1.1.1  2000/06/12 21:50:14  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.2  2000/06/12 19:58:34  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.1  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
</SCRIPT>