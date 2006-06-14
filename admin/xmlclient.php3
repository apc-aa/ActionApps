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

/** Cross-Server Networking - client module
 *
 * @params feed_id    - id of APC RSS Feed to proceed
 *         rssfeed_id - id of non-APC RSS Feed to proceed
 *         fill       - if fill=0 no data is written to the database
 *         time       - you can redefine the time for APC feeds from which you
 *                      want to feed items. Format: 2003-05-03T15:31:36+02:00
 *         url        - you can redefine the url of the feed
 *         debugfeed  - display debug nmessages
 *         display    - display the source of APC RSS feed
 *
 * Debugging
 *   There is a lot of debugging code in here, since this tends to be hard to debug
 *   Call with debugfeed=n parameter for different levels
 *   1	just errors that indicate a malfunction somewhere
 *   2	a list of feeds as they are processed
 *   3	+ list of messages recieved
 *   4	+ a list of messages rejected
 *   9	lots and lots more
 *
 *   This program can be called as for example:
 *   apc-aa/admin/xmlclient.php3?debugfeed=9&rssfeed_id=16
 */

// handle with PHP magic quotes - quote the variables if quoting is set off
function Myaddslashes($val, $n=1) {
  if (!is_array($val)) {
    return addslashes($val);
  }
  for (reset($val); list($k, $v) = each($val); )
    $ret[$k] = Myaddslashes($v, $n+1);
  return $ret;
}

if (!get_magic_quotes_gpc()) {
  // Overrides GPC variables
  if ( isset($HTTP_GET_VARS) AND is_array($HTTP_GET_VARS))
    for (reset($HTTP_GET_VARS); list($k, $v) = each($HTTP_GET_VARS); )
      $$k = Myaddslashes($v);
  if ( isset($HTTP_POST_VARS) AND is_array($HTTP_POST_VARS))
    for (reset($HTTP_POST_VARS); list($k, $v) = each($HTTP_POST_VARS); )
      $$k = Myaddslashes($v);
  if ( isset($HTTP_COOKIE_VARS) AND is_array($HTTP_COOKIE_VARS))
    for (reset($HTTP_COOKIE_VARS); list($k, $v) = each($HTTP_COOKIE_VARS); )
      $$k = Myaddslashes($v);
}


require_once "../include/config.php3";
require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."csn_util.php3"; // defines HTML and PLAIN as well as other functions
require_once AA_INC_PATH."xml_fetch.php3";
require_once AA_INC_PATH."xml_rssparse.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."itemfunc.php3";
require_once AA_INC_PATH."notify.php3";
require_once AA_INC_PATH."feeding.php3";
require_once AA_INC_PATH."sliceobj.php3";

if ($debugfeed >= 8) print("\n<br>XMLCLIENT STARTING");

// prepare Get variables

if ( $_GET['display'] ) {
    $fire = 'display';
} elseif ( isset($_GET['fill']) AND ($_GET['fill']==0) ) {
    $fire = 'test';
} else {
    $fire = 'write';   // default
}

if ($feed_id) {          // just one specified APC feed

    $feeds = apcfeeds(); // get all apc feeds definitions
    if ( $_GET['time'] ) {
        $feeds[$feed_id]['newest_item'] = $_GET['time'];
    }
    onefeed($feed_id, $feeds[$feed_id], $debugfeed, $fire);  // feed selected feed

} elseif ($rssfeed_id) { // just one specified RSS feed

    $rssfeeds = rssfeeds();
    if ( $_GET['url'] ) {
        $rssfeeds[$feed_id]['server_url'] = $_GET['url'];
    }
    onefeed($rssfeed_id, $rssfeeds[$rssfeed_id], $debugfeed, $fire);   // Not sure if its safe for feed_id to be same as for APC feeds

} else {                 // all RSS and APC feeds

    // we put the all teh feeds into an array and then we shuffle it
    // that makes the feeding in random order, so broken feeds do not stale
    // whole feeding
    $todo_feed = array();
    $apcfeeds  = apcfeeds();
    foreach ( $apcfeeds as $feed_id => $feed ) {
        $todo_feed[] = array($feed_id, $feed);
    }

    $rssfeeds = rssfeeds();
    foreach ( $rssfeeds as $feed_id => $feed ) {
        $todo_feed[] = array($feed_id, $feed);
    }

    shuffle($todo_feed);
    foreach ($todo_feed as $pair) {
        onefeed($pair[0], $pair[1]);
    }

}


?>
