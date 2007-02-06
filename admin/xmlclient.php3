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
require_once AA_INC_PATH."statestore.php3"; // AA_Object definition
require_once AA_INC_PATH."csn_util.php3"; // defines HTML and PLAIN as well as other functions
require_once AA_INC_PATH."xml_fetch.php3";
require_once AA_INC_PATH."xml_rssparse.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."itemfunc.php3";
require_once AA_INC_PATH."notify.php3";
require_once AA_INC_PATH."feeding.php3";
require_once AA_INC_PATH."sliceobj.php3";
require_once AA_INC_PATH."grabber.class.php3";

if ($debugfeed >= 8) print("\n<br>XMLCLIENT STARTING");

// prepare Get variables


if ( $_GET['display'] ) {
    $fire = 'display';
} elseif ( isset($_GET['fill']) AND ($_GET['fill']==0) ) {
    $fire = 'test';
} else {
    $fire = 'write';   // default
}


class AA_Feed {
    var $grabber;
    var $destination_slice_id;

    /** @var $fire - write | test | display
     *       - write   - feed and write the items to the databse
     *       - test    - proccesd without write anything to the database
     *       - display - only display the data from the feed
     */
    var $fire;

    function AA_Feed($grabber=null, $destination_slice_id=null, $fire='write') {
        $this->grabber              = $grabber;
        $this->destination_slice_id = $destination_slice_id;
        $this->fire                 = $fire;
    }

    function loadRSSFeed($id, $url=null) {
        $SQL = "SELECT feed_id, server_url, name, slice_id FROM rssfeeds WHERE feed_id='$id'";
        $feeddata                    = GetTable2Array($SQL, 'aa_first', 'aa_fields');
        $feeddata['feed_type']       = FEEDTYPE_RSS;
        // fictive remote slice id, but always the same for the same url
        $feeddata['remote_slice_id'] = q_pack_id(attr2id($feeddata['server_url']));

        $this->grabber               = new AA_Grabber_Aarss($id, $feeddata, $this->fire);
        $this->destination_slice_id  = unpack_id128($feeddata['slice_id']);

        if ($url) {
            $this->grabber->setUrl($url);
        }
    }


    function loadAAFeed($id, $time=null) {
        $SQL = "SELECT feed_id, password, server_url, name, slice_id, remote_slice_id, newest_item, user_id, remote_slice_name, feed_mode
                  FROM nodes, external_feeds WHERE nodes.name=external_feeds.node_name AND feed_id='$id'";
        $feeddata                    = GetTable2Array($SQL, 'aa_first', 'aa_fields');
        $feeddata['feed_type']       = ($feeddata['feed_mode'] == 'exact') ? FEEDTYPE_EXACT : FEEDTYPE_APC;

        $this->grabber               = new AA_Grabber_Aarss($id, $feeddata, $this->fire);
        $this->destination_slice_id  = unpack_id128($feeddata['slice_id']);

        if ($time) {
            $this->grabber->setTime($time);
        }
    }

    /** Process one feed RSS, or APC AA RSS, or CSV or ... - based on AA_Grabber
     *  @param  $feed_id   - id of feed (it is autoincremented number from 1 ...
     *                     - RSS and APC feeds could have the same id :-(
     *          $feed      - feed definition array (server_url, password, ...)
     *          $debugfeed - just for debuging purposes
     *          $fire      - write   - feed and write the items to the databse
     *                       test    - proccesd without write anything to the database
     *                       display - only display the data from the feed
     */
    function feed() {
        if ( $this->fire = 'write' ) {
            $translations = null;
            $saver        = new AA_Saver($this->grabber, $translations, $this->destination_slice_id);
            $saver->run();
        }
    }
}


if ($feed_id) {          // just one specified APC feed
    $feed = new AA_Feed();
    $feed->loadAAFeed($feed_id, $_GET['time']);
    $feed->feed();
} elseif ($rssfeed_id) { // just one specified RSS feed
    $feed = new AA_Feed();
    $feed->loadRSSFeed($rssfeed_id, $_GET['url']);
    $feed->feed();
} else {                 // all RSS and APC and general feeds
    $rssfeeds     = GetTable2Array('SELECT feed_id FROM rssfeeds', 'NoCoLuMn', 'feed_id');
    $aafeeds      = GetTable2Array('SELECT feed_id FROM external_feeds', 'NoCoLuMn', 'feed_id');
    $generalfeeds = AA_Object::getNameArray('AA_Feed', array(AA_ID));

    // we put all the feeds into an array and then we shuffle it
    // that makes the feeding in random order, so broken feeds do not stale
    // whole feeding
    $todo_feed = array();
    if ( is_array($rssfeeds) ) {
        foreach ( $rssfeeds as $v ) {
            $todo_feed[] = array('type'=>'rss', 'id'=>$v);
        }
    }
    if ( is_array($aafeeds) ) {
        foreach ( $aafeeds as $v ) {
            $todo_feed[] = array('type'=>'aarss', 'id'=>$v);
        }
    }
    if ( is_array($generalfeeds) ) {
        foreach ( $generalfeeds as $id => $name ) {
            $todo_feed[] = array('type'=>'general', 'id'=>$id);
        }
    }

    shuffle($todo_feed);
    foreach ($todo_feed as $feed_seting) {
        switch ($feed_seting['type']) {
            case 'aarss':
                $feed = new AA_Feed();
                $feed->loadAAFeed($feed_seting['id']);
                break;
            case 'rss':
                $feed = new AA_Feed();
                $feed->loadRSSFeed($feed_seting['id']);
                break;
            default:
                $feed = AA_Object::load($feed_seting['id']);
        }
        $feed->feed();
    }
}

?>
