<?php

use AA\IO\Feed;
use AA\Later\FeedsRun;
use AA\Util\InputVars;

/** Cross-Server Networking - client module
 *
 * @params feed_id    - id of APC RSS Feed to proceed
 *         rssfeed_id - id of non-APC RSS Feed to proceed
 *         fill       - if fill=0 no data is written to the database
 *         time       - you can redefine the time for APC feeds from which you
 *                      want to feed items. Format: 2003-05-03T15:31:36+02:00
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


// Feeds run from the task queue (AA\Later\FeedsRun); this page only serves
// the Run / Test / Source buttons, so it is for logged-in admins only.
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/xml_rssparse.php";
require_once __DIR__ . "/../include/itemfunc.php";
require_once __DIR__ . "/../include/notify.php";
require_once __DIR__ . "/../include/feeding.php";

try {
    $debugfeed  = InputVars::intGET('debugfeed');
    $feed_id    = InputVars::intGET('feed_id');
    $rssfeed_id = InputVars::intGET('rssfeed_id');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

// prepare Get variables
if ($_GET['display']) {
    $fire = 'display';
} elseif (isset($_GET['fill']) && $_GET['fill'] == 0) {
    $fire = 'test';
} else {
    $fire = 'write';   // default
}

/** Output the result of Feed::feed(): raw source for 'display' (escaped so the
 *  XML is visible), else the saver report / 'queued' message. */
function xmlclient_show(string $ret, string $fire): void
{
    if ($ret === '') {
        return;
    }
    $html = ($fire === 'display') ? '<pre>' . safe($ret) . '</pre>' : '<p>' . safe($ret) . '</p>';
    echo $html;
}


/** Same rule as the feeding pages with these buttons: superadmin, or PS_FEEDING
 *  on the slice the feed stores into. All feeds at once only for superadmin. */
function xmlclient_check_perm(?Feed $feed): void
{
    if (IsSuperadmin()) {
        return;
    }
    $dest = $feed?->getDestinationSliceId() ?? '';
    if (($dest === '') || !IfSlPerm(PS_FEEDING, $dest)) {
        MsgPage(_m("You do not have permissions to change feeding setting"));
    }
}

if ($feed_id) {          // just one specified APC feed
    $feed = new Feed();
    $feed->loadAAFeed($feed_id, $_GET['time']);
} elseif ($rssfeed_id) { // just one specified RSS feed
    $feed = new Feed();
    $feed->loadRSSFeed($rssfeed_id);
} else {                 // all RSS and APC feeds - the same run the queue does every 15 minutes
    $feed = null;
}
xmlclient_check_perm($feed);

// debug levels 8+ print the fetch URLs and the feed itself, which carry the node password
if (!IsSuperadmin()) {
    $debugfeed = min($debugfeed, 7);
}
// a remote fetch may take minutes - do not hold the user's other admin pages meanwhile
session_write_close();

($debugfeed >= 8) && print("\n<br>XMLCLIENT STARTING");
($debugfeed > 8) && $feed && huhl('feed', $fire, $feed);
xmlclient_show($feed ? $feed->feed($fire) : (new FeedsRun())->run($fire), $fire);
