<?php
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

/**
* Polls for APC ActionApps
*
* Based on phpPolls 1.0.3 from http://phpwizards.net (also under GPL v2.)
* You can supply just poll_id parameter - the first poll is displayed
* (the one in Active bin with oldest publish date and not expired)
* If you do not want the actual poll, you can display the second in the line
* by relative parameter (rel=1), the third (rel=2), ... You can also display
* already expired polls this way (rel=-1, rel=-2, ...)
*
* @author 	pavelji <pavel@cetoraz.info>
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
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."mgettext.php3";
require_once AA_BASE_PATH."modules/polls/showutils.php3";

/**
 * Handle with PHP magic quotes - quote the variables if quoting is set off
 * @param mixed $value the variable or array to quote (add slashes)
 * @return mixed the quoted variables (with added slashes)
 */
function AddslashesDeep($value) {
    return is_array($value) ? array_map('AddslashesDeep', $value) : addslashes($value);
}

if (!get_magic_quotes_gpc()) {
    // Overrides GPC variables
    foreach ($_GET as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_POST as $k => $v) {
        $kk = AddslashesDeep($v);
    }
    foreach ($_COOKIE as $k => $v) {
        $kk = AddslashesDeep($v);
    }
}

add_vars();

if (!isset($poll_id)) {
    echo _m("Error: poll_id is not valid");
    exit;
}

$encap = ( ($encap=="false") ? false : true );

if ($encap) {
    require_once AA_INC_PATH."locsessi.php3";
} else {
    require_once AA_INC_PATH."locsess.php3";
}
require_once AA_BASE_PATH."modules/polls/util.php3";

$db = new DB_AA;

if (isset($rel))
  $show = translateRelId($poll_id, $rel);

if (isset($vote) && isset($show) && !isset($no_vote)) {
    $poll = getValuesForPoll($poll_id, $show);
    registerVote($poll, $vote);
    $poll = getValuesForPoll($poll_id, $show);
}

// show poll(s)!
// next is already prepaired for display more than one poll
$show_ids = ( $show ? array( $show ) :
                      explode(";", $show_ids_str));

for ($i = 0; $i<count($show_ids); $i++) {
    $poll = getValuesForPoll($poll_id, $show_ids[$i]);

    $formats        = $poll["format"];
    $polldata       = $poll["polldata"];
    $poll["host"]   = $_SERVER["HTTP_HOST"];
    $poll["script"] = $_SERVER["SCRIPT_NAME"];
    $poll["params"] = explode(":", $poll["params"]);

    if ($debug) {
        echo "<pre>id: $poll_id, poll_id: $show_ids[$i]<br>";
        if ($debug==2) {
            print_r($poll);
            print_r($formats);
            print_r($polldata);
        }
        echo "</pre>";
    }
    printPoll($poll, $polldata, $formats);
}
?>
