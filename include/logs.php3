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
# Logging functions
#

# Write log entry
function writeLog($event, $params="" ) {
  global $db, $auth, $LOG_EVENTS;
  
  $params = addslashes($params);
  
  $SQL = "INSERT into log SET time='". time() ."', 
                              user='". $auth->auth["uid"] ."',
                              type='$event',
                              params='$params'";
  $db->query($SQL);
}

function getLogEvents($event, $from="", $to="", $group_by_param=false) {

}
    

?>