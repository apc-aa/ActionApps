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
# Cross-Server Networking - xml_aa_rss fetch function
#

require $GLOBALS[AA_INC_PATH]."logs.php3";

// fetch xml data from $url through http. This function is used by the rss aa module client as well as
// by the administrative interface
function  xml_fetch($url, $node_name, $password, $user, $slice_id, $start_timestamp, $categories) {

#          $node_name           - the name of the node making the request
#          $password            - the password of the node
#          $user                - a user at the remote node. This is the user who is trying
#                               - to establish a feed or who established the feed
#          $slice_id            - The id of the local slice from which a feed is requested
#          $start_timestamp     - a timestamp which indicates the creation time of the first item to be sent.
#                               - (www.w3.org/TR/NOTE-datetime format)
#          $categories          - a list of local categories ids separated by space (can be empty)

  $d["node_name"] = $node_name;
  $d["password"] = $password;
  $d["user"] = $user;
  $d["slice_id"] = $slice_id;
  $d["start_timestamp"] = $start_timestamp;
  $d["categories"] = $categories;

  while (list($k,$v) = each($d))
    if (!$v)
      unset($d[$k]);
    else
      $d[$k] = $k."=".urlencode($v);

  $url = $url."?".implode("&",$d);
  if (!($fp = fopen($url, "r"))) {
    writeLog("CSN","Unable to connect remote node $url");
    return false;
  }
 $data = fread($fp, 4000000);
 fclose($fp);

 return $data;
}
/*
$Log$
Revision 1.1  2001/09/27 13:09:53  honzam
New Cross Server Networking now is working (RSS item exchange)

*/

?>