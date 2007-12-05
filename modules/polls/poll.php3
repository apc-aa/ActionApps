<?php
/**
* Polls module is based on Till Gerken's phpPolls version 1.0.3. Thanks!
*
* PHP versions 4 and 5
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
* @author    Pavel Jisl <pavel@cetoraz.info>, Honza Malik <honza.malik@ecn.cz>
* @license   http://opensource.org/licenses/gpl-license.php GNU Public License
* @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
* @link      http://www.apc.org/ APC
*
*/

/**
* Polls for ActionApps
*
* Based on phpPolls 1.0.3 from http://phpwizards.net (also under GPL v2.)
* You can supply just poll_id parameter - the first poll is displayed
* (the one in Active bin with oldest publish date and not expired)
* If you do not want the actual poll, you can display the second in the line
* by relative parameter (rel=1), the third (rel=2), ... You can also display
* already expired polls this way (rel=-1, rel=-2, ...)
*
* @param    string   poll_id    32 digit long hexadecimal id of poll (see Polls
*                               Admin). This parameter is REQUIRED.
* @param  	integer  show       id of poll to show (the id is displayed in AA
*                               admin interface - Polls Manager)
* @param  	integer  rel        show relative poll (current +/- rel)
* @param    integer  no_vote    if no_vote=1, user votes are ignored (used for
*                               displaying old polls results)
*/



// input parameters:
//
// * id - id of polls
// * poll_id - id of one poll
//
// to show more polls, if to_id isn't set, we show all polls from from_id
// * from_id
// * to_id
//
// relative show of polls, parameters aren't IDS!!!!!!
// * rel

require_once "../../include/config.php3";
require_once AA_INC_PATH. "util.php3";
require_once AA_INC_PATH. "mgettext.php3";
require_once AA_INC_PATH. "searchlib.php3";
require_once AA_INC_PATH. "item.php3";
require_once AA_INC_PATH. "itemview.php3";
require_once AA_BASE_PATH."modules/polls/include/util.php3";
require_once AA_BASE_PATH."modules/polls/include/stringexpand.php3";
require_once AA_BASE_PATH."modules/polls/include/poll.class.php3";

/**
 * Handle with PHP magic quotes - quote the variables if quoting is set off
 * @param mixed $value the variable or array to quote (add slashes)
 * @return mixed the quoted variables (with added slashes)
 */
function StripslashesDeep($value) {
    return is_array($value) ? array_map('StripslashesDeep', $value) : stripslashes($value);
}

if ( get_magic_quotes_gpc() ) {
    $_POST    = StripslashesDeep($_POST);
    $_GET     = StripslashesDeep($_GET);
    $_REQUEST = StripslashesDeep($_REQUEST);
    $_COOKIE  = StripslashesDeep($_COOKIE);
}

add_vars('', '_REQUEST');

$encap = ( ($encap=="false") ? false : true );
require_once AA_INC_PATH. ($encap ? "locsessi.php3" : "locsess.php3");

$db = new DB_AA;

huhl($_REQUEST);

if (isset($_REQUEST['vote_id']) AND isset($_REQUEST['poll_id'])) {
    $poll = AA_Polls::getPoll($_REQUEST['poll_id']);
    $poll->registerVote($_REQUEST['vote_id']);
}

if ($_REQUEST['poll_id']) {
    // we want to display specified poll, or we just voted
    $poll_zids = new zids($_REQUEST['poll_id']);
} else {

    $set = new AA_Set;
    $set->addCondition(new AA_Condition('module_id',   '==', q_pack_id($_REQUEST['pid'])));
    // there is also one poll which acts as template - managed from Polls Admin
    // (and not from the Polls Manager page) - it has status_code=0,
    // so it is filtered out automaticaly
    
    $now = now();
    $set->addCondition(new AA_Condition('status_code', '=', '1'));
    $set->addCondition(new AA_Condition('expiry_date', '>=', $now));
    $set->addCondition(new AA_Condition('publish_date', '<=', $now));
    
    if ($conds) {
        $set->addCondsFromArray($conds);
    }
    
    if ($sort) {
        $set->addSortFromArray($sort);
    } else {
        // default sort order - just like for items - publish date - descending
        $set->addSortorder( new AA_Sortorder( array('publish_date' => 'd')));
    }
    
    $poll_zids = AA_Metabase::queryZids(array('table'=>'polls'), $set);
    $poll_zids = $poll_zids->slice(0, $listlen ? $listlen : 1);
}

// and now display the polls
$zid_count = $poll_zids->count();

for ( $i=0; $i < $zid_count; $i++ ) {
    $poll = AA_Polls::getPoll($poll_zids->id($i));
    $poll->display($_REQUEST['vote_id'] ? 'aftervote' : 'beforevote');
}

exit;
?>
