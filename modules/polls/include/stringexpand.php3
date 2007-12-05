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



require_once "constants.php3";

/** Expands {poll:<poll_ids>:<aa_expression>} and displays the aa_expression
 *  for the poll
 *  @param $poll_id       - id of the poll (not pioll module, but poll as one question)
 *  @param $aa_expression - field from 'polls' table or any other expression
 */
class AA_Stringexpand_Poll extends AA_Stringexpand {

    /** expand function
     * @param $ids_string
     * @param $expression
     */
    function expand($ids_string='', $expression='') {
        $ids     = explode('-', $ids_string);
        $results = array();
        $count   = 0;
        if ( is_array($ids) ) {
            foreach ( $ids as $poll_id ) {
                if ( $poll_id ) {
                    $poll = AA_Polls::getPoll($poll_id);
                    if ($poll) {
                        $count++;
                        if ($expression) {
                            $results[$poll_id] = $poll->unalias($expression);
                        }
                    }
                }
            }
        }
        return join('',$results);
    }
}

/** Expands {poll_share[:<max>]} number representing current share of the votes
 *  for the answer. By default in scale of 0-100, so it could be used as percent
 *  value. You can specify the max parameter, so the values could be from 0 to "" 
 *  $max which could be used as image width, ... 
 *  @param $max  
 */
class AA_Stringexpand_Poll_Share extends AA_Stringexpand {

    /** expand function
     * @param $max - maximum the votes for the answer could reach, default is 100 
     */
    function expand($max='') {
        $poll     = AA_Polls::getPoll($this->item->getVal('poll_id'));
        $sum      = $poll->getVotesSum();
        $quotient = $max ? $max : 100;
        
        return $sum==0 ? 0 : round(($this->item->getVal('votes')/$sum) * $quotient);
    }
}

/** Expands {poll_sum} with number of all votes in this poll */
class AA_Stringexpand_Poll_Sum extends AA_Stringexpand {

    /** expand function - number of all votes in this poll */
    function expand() {
        $poll     = AA_Polls::getPoll($this->item->getVal('poll_id'));
        return $poll->getVotesSum();
    }
}





function printBarForVote($poll, $polldata, $which) {
    $color = getColors($poll);

    $num_count=0;
    for ($i=0; $i<=count($polldata); $i++) {
        $num_count += $polldata[$i]["votes"];
    }
    if (($num_count != 0) && ($polldata[$which]["votes"] != 0)) {
        $width = round($polldata[$which]["votes"]*(($poll["format"]["resultBarWidth"]+10)/$num_count));
    } else {
        $width = 10;
    }
    if ($poll["params"][0] == "1") {
        $vote_url = 'http://'.$poll["host"].$poll["script"];
        $vote_url = substr($vote_url, 0, strrpos($vote_url,"/")+1);
        $vote_url .= "vote.php3";
    } else {
        $vote_url = $poll["url"];
    }
    $adr = "<a href=\"$vote_url?poll_id=".$poll["unpacked_id"];
    if ($poll["params"][0] == "1") {
        $adr .= "&redir=http://".$poll["host"].$poll["url"];
    }
    $adr .= "&id=".$poll["poll_id"]."&vote=".$which."\">";

    if ($poll["format"]["params"][1] == "1") {
        $img = (($poll["dont_vote"] != 1) ? $adr : "").
               "<img alt=\"".$polldata[$which]["answer"]."\" border=0 width=\"$width\" height=\"".$poll["format"]["resultBarHeight"]."\" src=\"".$poll["format"]["resultBarFile"]."\">".
               (($poll["dont_vote"] != 1) ? "</a>" : "");
    } else {
        /*
          if (function_exists('imagejpeg')) {
            $url = $poll["host"].$poll["script"];
            $url = substr($url, 0, strrpos($url,"/")+1);
            $url .= "image.php3";

            $img = (($poll["dont_vote"] != 1) ? $adr : "").podíl fraction
             "<span style=\"background:$color[$which]\">".
             "<img alt=\"".$polldata[$which]["answer"]."\" border=0 width=\"$width\" height=\"".$poll["format"]["resultBarHeight"]."\" src=\"".$url."?color=".$color[$which]."&height=".$poll["format"]["resultBarHeight"]."&width=".$poll["format"]["resultBarWidth"]."\">".
          "</span>".
          (($poll["dont_vote"] != 1) ? "</a>" : "")."\n";
          } else {
        */
        $img = (($poll["dont_vote"] != 1) ? $adr : "").
               "<span style=\"background:$color[$which]\">".
               "<img alt=\"".$polldata[$which]["answer"]."\" border=0 width=\"$width\" height=\"".$poll["format"]["resultBarHeight"]."\" src=\"".$poll["format"]["resultBarFile"]."\">".
               "</span>".
               (($poll["dont_vote"] != 1) ? "</a>" : "")."\n";
        /*  }       */
    }
    return $img;
}


//
// polls_desings - parameters
// 1. - colors of our generated picture
// 2. - use our image (0) or image from "URL to bar image"
//

function showQuestion($poll, $polldata, $which=0) {
    return $poll["headline"];
}

function showAnswer($poll, $polldata, $which) {
    return $polldata[$which]["answer"];
}

function showNumAllVotes($poll, $polldata, $which) {
    for ($i=0; $i<=count($polldata); $i++) {
        $num_count += $polldata[$i]["votes"];
    }
    return $num_count;
}

function showAnswerPercents($poll, $polldata, $which) {
    for ($i=0; $i<=count($polldata); $i++) {
        $num_count += $polldata[$i]["votes"];
    }
    if ($num_count != 0) {
        $out = ($polldata[$which]["votes"]/$num_count)*100;
    } else {
        $out = 0;
    }
    $out = round($out);
    return $out."%";
}

function showAnswerVotes($poll, $polldata, $which) {
    return $polldata[$which]["votes"];
}

function printAnswNumber($poll, $polldata, $which) {
    return $which;
}

function getColors($poll) {
    global $COLORNAMES;
    if (strlen(substr($poll["format"]["params"],0,strpos($poll["format"]["params"],":")))!=0) {
        $color = split(",",substr($poll["format"]["params"],0,strpos($poll["format"]["params"],":")));
    } else {
        reset($COLORNAMES);
        next($COLORNAMES);
        next($COLORNAMES);
        next($COLORNAMES);
        next($COLORNAMES);
        while (next($COLORNAMES)) {
            $color[] = current($COLORNAMES);
        }
    }
    return $color;
}

function showBar($poll, $polldata, $which) {
    $color = getColors($poll);
    $num_count=0;
    for ($i=0; $i<=count($polldata); $i++) {
        $num_count += $polldata[$i]["votes"];
    }
    if ($num_count != 0) {
        $width = round($polldata[$which]["votes"]*($poll["format"]["resultBarWidth"]/$num_count));
    } else {
        $width = 10;
    }
    if (function_exists('imagejpeg')) {
        $url = $poll["host"].$poll["script"];
        $url = substr($url, 0, strrpos($url,"/")+1);
        $url .= "image.php3";
        $img = "<img alt=\"".$polldata[$which]["answer"]."\" border=0 width=\"$width\" height=\"".$poll["format"]["resultBarHeight"]."\" src=\"".$url."?color=".$color[$which]."&height=".$poll["format"]["resultBarHeight"]."&width=".$poll["format"]["resultBarWidth"]."\">\n</div>\n";
    } else {
        $img = "<span style=\"background:".$color[$which]."\">\n".
               "<img alt=\"".$polldata[$which]["answer"]."\" border=0 width=\"$width\" height=\"".$poll["format"]["resultBarHeight"]."\" src=\"".$poll["format"]["resultBarFile"]."\">\n</span>\n";
    }
    return $img;
}




function printRadioButton($poll, $polldata, $which) {
    if ($poll["dont_vote"] != 1) {
        $out = "\n<input type=\"radio\" name=\"vote\" value=\"".$which."\"".(( $which=="1") ? " checked" : "").">\n";
    } else {
        $out = "";
    }
    return $out;
}

function printSendButton($poll, $polldata, $which) {
    if ($poll["dont_vote"] != 1) {
        $out = "\n<input type=\"hidden\" name=\"id\" value=\"".$poll["poll_id"]."\">\n";
        $out .= "<input type=\"hidden\" name=\"poll_id\" value=\"".$poll["unpacked_id"]."\">\n";
        $out.= "<input type=\"submit\" name=\"submit\" value=\""._m("Submit")."\">";
    } else {
        $out = "";
    }
    return $out;
}

function parseDesignAliases($format, $poll, $polldata, $polloption) {
    $aliases = GetPollsAliases();

    $pieces = explode("_#", $format);
    reset($pieces);
    $out = current($pieces);   // initial sequence

    while ( $vparam = next($pieces) ) {
        //search for alias definition (fce,param,hlp)
        $fnc = $aliases["_#".substr($vparam,0,8)]["fce"];
        if ($fnc != "") {
            $substitution = $fnc($poll, $polldata, $polloption);
            $out .= str_replace(substr($vparam,0,8), $substitution, $vparam);
        } else {
            $out .= "";
        }
    }
    return $out;
}

function showPollPreview() {
    $design = parseDesignAliases($formats["top"], $poll, $polldata, 0);
    for ($i=1; $i<$vote_count; $i++) {
        $design.=parseDesignAliases($formats["answer"], $poll, $polldata, $i);
    }
    $design.=parseDesignAliases($formats["bottom"], $poll, $polldata, 0);
    return $design;
}

function printPoll($poll, $polldata, $formats) {

    echo "\n\n<!-- polls module for AA, generated code -->\n";
    if ($poll["params"][0] == "1") {
        $vote_url = $poll["host"].$poll["script"];
        $vote_url = substr($vote_url, 0, strrpos($vote_url,"/")+1);
        $vote_url .= "vote.php3";
        echo "<form method=get action=\"http://". $vote_url ."\">\n";
    } else {
        echo "<form method=get action=\"". $poll["url"] ."\">\n";
    }
    echo parseDesignAliases($formats["top"], $poll, $polldata, 0);
    for ($i=1; $i<$poll["vote_count"]; $i++) {
        echo parseDesignAliases($formats["answer"], $poll, $polldata, $i);
    }
    echo parseDesignAliases($formats["bottom"], $poll, $polldata, 0);
    if ($poll["params"][0]) {
        echo "<input type=\"hidden\" name=\"redir\" value=\"http://".$poll["host"].$poll["url"]."\">\n";
    }
    echo "\n</form>";
    echo "\n<!-- end of generated code from polls module for AA -->\n\n";
}

function translateRelId($module_id, $rel) {
    global $db;

    $now = now();
    $p_module_id = q_pack_id($module_id);

    $SQL = "SELECT * FROM polls WHERE ((module_id='$p_module_id') AND (status_code = '1')) ORDER BY publish_date";
    $db->query($SQL);
    $counter = 0;
    $zero_pos = -1;                          // pointer to current poll (rel=0)
    while ($db->next_record()) {
        if ( ($zero_pos == -1)  AND           // not set yet
            ($db->f('expiry_date') >= $now) AND
            ($db->f('publish_date') <= $now) )
            $zero_pos = $counter;            // mark id which is rel=0
        $dummy[$counter++] = $db->f('poll_id');
    }

    return( $dummy[$zero_pos + (int)$rel] );  // returns false if it is not valid
                                             // index
}


?>
