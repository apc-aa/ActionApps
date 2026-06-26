<?php

use AA\IO\DB\DB_AA;
use AA\IO\Feed;
use AA\Later\Toexecute;
use AA\Util\InputVars;

/** Cross-Server Networking - client module
 *
 * @params feed_id    - id of APC RSS Feed to proceed
 *         rssfeed_id - id of non-APC RSS Feed to proceed
 *         fill       - if fill=0 no data is written to the database
 *         time       - you can redefine the time for APC feeds from which you
 *                      want to feed items. Format: 2003-05-03T15:31:36+02:00
 *         url        - you can redefine the url of the feed
 *         debugfeed  - display debug messages
 *         display    - display the source of APC RSS feed
 *
 * Debugging
 *   There is a lot of debugging code in here, since this tends to be hard to debug
 *   Call with debugfeed=n parameter for different levels
 *   1	just errors that indicate a malfunction somewhere
 *   2	a list of feeds as they are processed
 *   3	+ list of messages received
 *   4	+ a list of messages rejected
 *   9	lots and lots more
 *
 *   This program can be called as for example:
 *   apc-aa/admin/xmlclient.php?debugfeed=9&rssfeed_id=16
 *
 *
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

// ----- input variables normalization - start --------------------------------

// ----- input variables normalization - end ----------------------------------


// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/config.php";
require_once __DIR__ . "/../include/locsess.php";
require_once __DIR__ . "/../include/util.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/xml_rssparse.php";
require_once __DIR__ . "/../include/itemfunc.php";
require_once __DIR__ . "/../include/notify.php";
require_once __DIR__ . "/../include/feeding.php";

try {
    $debugfeed  = InputVars::intGET('debugfeed');
    $feed_id    = InputVars::intGET('feed_id');
    $rssfeed_id = InputVars::intGET('rssfeed_id');
    $cancel = InputVars::boolPOST('cancel');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

($debugfeed >= 8) && print("\n<br>XMLCLIENT STARTING");

// prepare Get variables
if ($_GET['display']) {
    $fire = 'display';
} elseif (isset($_GET['fill']) && $_GET['fill'] == 0) {
    $fire = 'test';
} else {
    $fire = 'write';   // default
}

/** Output the result of Feed::feed(): raw source for 'display' (escaped so the
 *  XML is visible), else the saver report / 'queued' message. (Previously the
 *  return value was silently discarded, so Source showed a blank page.) */
function xmlclient_show(string $ret, string $fire): void
{
    if ($ret === '') {
        return;
    }
    $html = ($fire === 'display') ? '<pre>' . safe($ret) . '</pre>' : '<p>' . safe($ret) . '</p>';
    echo $html;
}


if ($feed_id) {          // just one specified APC feed
    $feed = new Feed();
    $feed->loadAAFeed($feed_id, $_GET['time']);
    ($debugfeed > 8) && huhl('aafeed', $fire, $feed);
    xmlclient_show($feed->feed($fire), $fire);
} elseif ($rssfeed_id) { // just one specified RSS feed
    $feed = new Feed();
    $feed->loadRSSFeed($rssfeed_id, $_GET['url']);
    ($debugfeed > 8) && huhl('rssfeed', $fire, $feed);
    xmlclient_show($feed->feed($fire), $fire);
} else {                 // all RSS and APC and general feeds
    $rssfeeds     = GetTable2Array('SELECT feed_id FROM rssfeeds', 'NoCoLuMn', 'feed_id');
    $aafeeds      = GetTable2Array('SELECT feed_id FROM external_feeds', 'NoCoLuMn', 'feed_id');
    $generalfeeds = AA_Object::getNameArray('AA\IO\Feed', [AA_ID]);

    // we put all the feeds into an array and then we shuffle it
    // that makes the feeding in random order, so broken feeds do not stale
    // whole feeding
    $todo_feed = [];
    if (is_array($rssfeeds)) {
        foreach ($rssfeeds as $v) {
            $todo_feed[] = ['type' => 'rss', 'id' => $v];
        }
    }
    if (is_array($aafeeds)) {
        foreach ($aafeeds as $v) {
            $todo_feed[] = ['type' => 'aarss', 'id' => $v];
        }
    }
    if (is_array($generalfeeds)) {
        foreach (array_keys($generalfeeds) as $id) {
            $todo_feed[] = ['type' => 'general', 'id' => $id];
        }
    }

    shuffle($todo_feed);
    foreach ($todo_feed as $feed_seting) {
        switch ($feed_seting['type']) {
            case 'aarss':
                $feed = new Feed();
                $feed->loadAAFeed($feed_seting['id']);
                break;
            case 'rss':
                $feed = new Feed();
                $feed->loadRSSFeed($feed_seting['id']);
                break;
            default:
                $feed = AA_Object::load($feed_seting['id']);
        }

        xmlclient_show($feed->feed($fire), $fire);
    }
}
