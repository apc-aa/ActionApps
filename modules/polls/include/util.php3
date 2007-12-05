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

require_once AA_INC_PATH. "mgettext.php3";
require_once AA_INC_PATH. "varset.php3";
require_once AA_BASE_PATH."modules/polls/include/constants.php3";

// Miscellaneous utility functions for the module

function getPollOutput($poll_zid) {
    $poll_id  = $poll_zid->id();
    $poll     = AA_Polls::getPoll($poll_id);
    $format   = $poll->get_format_strings();

    $metabase = AA_Metabase::singleton();
    $aliases  = GetAnswerAliases();
    $fields   = $metabase->getSearchArray('polls_answer');

    $set = new AA_Set;
    $set->addCondition(new AA_Condition('poll_id', '==', $poll_id));
    $set->addSortorder(new AA_Sortorder(array('priority' => 'a')));

    $zids = $metabase->queryZids(array('table'=>'polls_answer'), $set);

    $content_function = array(array('AA_Metabase', 'getContent'), array('table'=>'polls_answer'));

    $itemview = new itemview( $format, $fields, $aliases, $zids, 0, $zids->count(), shtml_url(), "", $content_function);
    return $itemview->get_output_cached();
}

/** Predefined aliases for polls. For another aliases use 'inline' aliases. */
function GetAnswerAliases() {
    $metabase = AA_Metabase::singleton();
    $aliases = array (
        "_#POLLQUES" => GetAliasDef( "f_t:{poll:{_#POLL_ID_}:_#HEADLINE}",        "",                _m('Prints poll answer')),
        "_#ANS_NO__" => GetAliasDef( "f_t",       "priority",                _m('Nubmer of answer')),
        "_#ANS_VOTE" => GetAliasDef( "f_t",          "votes",                _m('Nubmer of votes for this answer')),
        "_#ANSWER__" => GetAliasDef( "f_t",         "answer",                _m('Text of answer')), // generated automaticaly form the table column using metabase methods
        "_#ANS_ID__" => GetAliasDef( "f_t",             "id",                _m('ID of answer (32 characters hexadecimal number)')),
        "_#POLL_ID_" => GetAliasDef( "f_t",        "poll_id",                _m('Poll id (32 characters hexadecimal number - the same for all answers)')), // generated automaticaly form the table column using metabase methods
        "_#ANS_PERC" => GetAliasDef( "f_t:{poll_share}",     "",                _m('Votes for this answer in percent. You can use also {poll_share}.')),
        "_#ANS_SUM_" => GetAliasDef( "f_t:{poll_sum}",       "",                _m('Sum of all votes. You can use also {poll_sum}.')),
/*        "_#POLLANSW" => GetAliasDef( "showAnswer",         '', _m("Prints poll answer")),
        "_#BAR_RES#" => GetAliasDef( "showBar",            '', _m("Shows bar image")),
        "_#POLLVTS#" => GetAliasDef( "showAnswerVotes",    '', _m("Shows number of voters of this answer")),
        "_#ALLVOTES" => GetAliasDef( "showNumAllVotes",    '', _m("Shows number of all voters")),
        "_#POLLPERC" => GetAliasDef( "showAnswerPercents", '', _m("Shows percentage of voters of this answer")),
        "_#POLL_ID#" => GetAliasDef( "showID",             '', _m("alias for Poll ID")),
        "_#BAR_VOTE" => GetAliasDef( "printBarForVote",    '', _m("prints bar, whitch is used for voting (after click)")),
        "_#RADIOVOT" => GetAliasDef( "printRadioButton",   '', _m("prints radiobutton, used for voting")),
        "_#SENDBTN#" => GetAliasDef( "printSendButton",    '', _m("prints Send button")),
        "_#VOTENUM#" => GetAliasDef( "printAnswNumber",    '', _m("prints number of answer")),
*/
    );
//    return array_merge($metabase->generateAliases('polls_answer'), $aliases);
    return $aliases;
}


/** GetPollAnswersContent function for loading content of poll ANSWERS!
 *  It loads ANSWERS, not the list of polls ()
 *
 * Loads all the answers for 1 poll and stores it in
 * the 'Abstract Data Structure' for use with 'item' class
 *
 * @see GetItemContent(), itemview class, item class
 * @param array $zids array of poll ids to get from database - It makes no sense
 *                    to provide mode then one poll id here (zids) - since we do
 *                    not want to mix answers from more polls
 *
 * @return array - Abstract Data Structure containing the links data
 *                 {@link http://apc-aa.sourceforge.net/faq/#1337}
 */
function GetPollAnswersContent($zids) {
    $content = array();
    $ret     = array();

    // construct WHERE clausule
    $sel_in = $zids->sqlin( false );
    $SQL = "SELECT * FROM polls_answer WHERE poll_id $sel_in";
    StoreTable2Content($content, $SQL, '', 'poll_id');
    // it is unordered, so we have to sort it:
    for($i=0; $i<$zids->count(); $i++ ) {
        $ret[(string)$zids->id($i)] = $content[$zids->id($i)];
    }
    return $ret;
}

function getValuesForPoll($module_id, $show) {
    global $db, $OPTIMIZE_FOR_MYSQL;

    if ( !$show )
      return false;

    $SQL = "SELECT * FROM polls WHERE ((module_id='".  q_pack_id($module_id) ."') AND (id='".$show."'))";
    $db->query($SQL);
    if ( !$db->next_record())
        return false;
    $poll = $db->Record;
    if ( ($poll["status_code"] != '1') OR ($poll["expiry_date"] < $now) )
        $poll["dont_vote"] = 1;      // do not vote in expired
                                     // or trashed poll

    $poll["unpacked_id"] = unpack_id($poll["id"]);

    $SQL = "SELECT * FROM polls_design WHERE ((module_id='". q_pack_id($module_id)."') AND (id = '".$poll["design_id"]."'))";
    $db->query($SQL);
    if ($db->next_record()) {
        $formats = $db->Record;
        $poll["format"] = $formats;
    }

    $SQL = "SELECT * FROM polls_answer WHERE (poll_id='".$poll["poll_id"]."')";
    $db->query($SQL);
    $vote_count=1;
    while ($db->next_record()) {
        $polldata[$vote_count]=$db->Record;
        $vote_count++;
    }
    $poll["polldata"] = $polldata;
    $poll["vote_count"] = $vote_count;
    $poll["url"] = shtml_url();

    return $poll;
}

function printAliases() {
    $aliases = GetPollsAliases();
    echo "<center><table>";
    PrintAliasHelp($aliases);
    echo "</table></center>";
}

function registerVote($poll, $vote) {
    global $db;

    $vote_invalid = "";
    $current_time = time();

    // checkig for duplicated votes - ip_locking method
    if ($poll["ip_locking"] == 1) {
        $SQL = "SELECT * FROM polls_ip_lock WHERE poll_id='". $poll["poll_id"]."'";
        $db->query($SQL);

        while ($db->next_record() ){
            $ip_lock = $db->Record;
            if (($ip_lock["timestamp"]+$poll["ip_lock_timeout"]) < $current_time) {
                $SQL = "DELETE FROM polls_ip_lock WHERE timestamp='".$ip_lock["timestamp"]."'";
                $db->query($SQL);
                unset($ip_lock);
            }
        }
        $SQL = "SELECT * FROM polls_ip_lock WHERE (poll_id='". $poll["poll_id"]."') AND (voters_ip = '".$_SERVER['REMOTE_ADDR']."')";
        $db->query($SQL);
        $count=0;
        while ($db->next_record()){
            $count++;
        }
        if ($count == 0) {
            $SQL = "INSERT INTO polls_ip_lock (poll_id, voters_ip, timestamp) VALUES ('".$poll["poll_id"]."', '".$_SERVER['REMOTE_ADDR']."', '".$current_time."')";
            $db->query($SQL);
        } else {
            $vote_invalid = "IP";
        }
        // end ip_locking
    }

    // checkig for duplicated votes - Cookies method
    if ($poll["set_cookies"] == 1) {
        $SQL = "SELECT publish_date FROM polls WHERE id='".$poll["poll_id"]."'";
        $db->query($SQL);
        $db->next_record();
        $publish_date = $db->f("publish_date");
        $cookie = $poll["cookies_prefix"].$publish_date;
        if ($$cookie == "1") {
            $vote_invalid = "Cookie";
        } else {
            setCookie($cookie, "1");
        }
        // end Cookies
    }
    //  echo "your ip: $_SERVER['REMOTE_ADDR'] vote_invalid: $vote_invalid";
    if ($vote_invalid == "") {
        $SQL = "UPDATE polls_answer SET votes=votes+1 WHERE (poll_id='".$poll["poll_id"]."') AND (id='".$vote."')";
        $db->query($SQL);
        if ($poll["Logging"] == 1) {
            $SQL = "INSERT INTO polls_log (poll_id, answer_id, voters_ip, timestamp) VALUES ('".$poll["poll_id"]."', '".$vote."', '".$_SERVER['REMOTE_ADDR']."', '".$current_time."')";
            $db->query($SQL);
        }
    }

    //end registerVote
}


?>
