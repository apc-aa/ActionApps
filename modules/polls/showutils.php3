<?php

require_once "constants.php3";

//
// polls_desings - parameters
// 1. - colors of our generated picture
// 2. - use our image (0) or image from "URL to bar image"
//

function showAnswer($poll, $polldata, $which) {
    return $polldata[$which]["optionText"];
}


function showNumAllVotes($poll, $polldata, $which) {
    for ($i=0; $i<=count($polldata); $i++) {
        $num_count += $polldata[$i]["optionCount"];
    }
    return $num_count;
}

function showAnswerPercents($poll, $polldata, $which) {
    for ($i=0; $i<=count($polldata); $i++) {
        $num_count += $polldata[$i]["optionCount"];
    }
    if ($num_count != 0) {
        $out = ($polldata[$which]["optionCount"]/$num_count)*100;
    } else {
        $out = 0;
    }
    $out = round($out);
    return $out."%";
}

function showAnswerVotes($poll, $polldata, $which) {
    return $polldata[$which]["optionCount"];
}

function printAnswNumber($poll, $polldata, $which) {
    return $which;
}

function getColors($poll) {
    global $colornames;
    if (strlen(substr($poll["format"]["params"],0,strpos($poll["format"]["params"],":")))!=0) {
        $color = split(",",substr($poll["format"]["params"],0,strpos($poll["format"]["params"],":")));
    } else {
        reset($colornames);
        next($colornames);
        next($colornames);
        next($colornames);
        next($colornames);
        while (next($colornames)) {
            $color[] = current($colornames);
        }
    }
    return $color;
}

function showBar($poll, $polldata, $which) {
    $color = getColors($poll);
    $num_count=0;
    for ($i=0; $i<=count($polldata); $i++) {
        $num_count += $polldata[$i]["optionCount"];
    }
    if ($num_count != 0) {
        $width = round($polldata[$which]["optionCount"]*($poll["format"]["resultBarWidth"]/$num_count));
    } else {
        $width = 10;
    }
    if (function_exists('imagejpeg')) {
        $url = $poll["host"].$poll["script"];
        $url = substr($url, 0, strrpos($url,"/")+1);
        $url .= "image.php3";
        $img = "<img alt=\"".$polldata[$which]["optionText"]."\" border=0 width=\"$width\" height=\"".$poll["format"]["resultBarHeight"]."\" src=\"".$url."?color=".$color[$which]."&height=".$poll["format"]["resultBarHeight"]."&width=".$poll["format"]["resultBarWidth"]."\">\n</div>\n";
    } else {
        $img = "<span style=\"background:".$color[$which]."\">\n".
               "<img alt=\"".$polldata[$which]["optionText"]."\" border=0 width=\"$width\" height=\"".$poll["format"]["resultBarHeight"]."\" src=\"".$poll["format"]["resultBarFile"]."\">\n</span>\n";
    }
    return $img;
}


function printBarForVote($poll, $polldata, $which) {
    $color = getColors($poll);

    $num_count=0;
    for ($i=0; $i<=count($polldata); $i++) {
        $num_count += $polldata[$i]["optionCount"];
    }
    if (($num_count != 0) && ($polldata[$which]["optionCount"] != 0)) {
        $width = round($polldata[$which]["optionCount"]*(($poll["format"]["resultBarWidth"]+10)/$num_count));
    } else {
        $width = 10;
    }
    if ($poll["params"][0] == "1") {
        $vote_url = $poll["host"].$poll["script"];
        $vote_url = substr($vote_url, 0, strrpos($vote_url,"/")+1);
        $vote_url .= "vote.php3";
    } else {
        $vote_url = $poll["host"].$poll["url"];
    }
    $adr = "<a href=\"http://".$vote_url."?poll_id=".$poll["unpacked_id"];
    if ($poll["params"][0] == "1") {
        $adr .= "&redir=http://".$poll["host"].$poll["url"];
    }
    $adr .= "&id=".$poll["pollID"]."&vote=".$which."\">";

    if ($poll["format"]["params"][1] == "1") {
        $img = (($poll["dont_vote"] != 1) ? $adr : "").
               "<img alt=\"".$polldata[$which]["optionText"]."\" border=0 width=\"$width\" height=\"".$poll["format"]["resultBarHeight"]."\" src=\"".$poll["format"]["resultBarFile"]."\">".
               (($poll["dont_vote"] != 1) ? "</a>" : "");
    } else {
        /*
          if (function_exists('imagejpeg')) {
            $url = $poll["host"].$poll["script"];
            $url = substr($url, 0, strrpos($url,"/")+1);
            $url .= "image.php3";

            $img = (($poll["dont_vote"] != 1) ? $adr : "").
             "<span style=\"background:$color[$which]\">".
             "<img alt=\"".$polldata[$which]["optionText"]."\" border=0 width=\"$width\" height=\"".$poll["format"]["resultBarHeight"]."\" src=\"".$url."?color=".$color[$which]."&height=".$poll["format"]["resultBarHeight"]."&width=".$poll["format"]["resultBarWidth"]."\">".
          "</span>".
          (($poll["dont_vote"] != 1) ? "</a>" : "")."\n";
          } else {
        */
        $img = (($poll["dont_vote"] != 1) ? $adr : "").
               "<span style=\"background:$color[$which]\">".
               "<img alt=\"".$polldata[$which]["optionText"]."\" border=0 width=\"$width\" height=\"".$poll["format"]["resultBarHeight"]."\" src=\"".$poll["format"]["resultBarFile"]."\">".
               "</span>".
               (($poll["dont_vote"] != 1) ? "</a>" : "")."\n";
        /*  }       */
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
        $out = "\n<input type=\"hidden\" name=\"id\" value=\"".$poll["pollID"]."\">\n";
        $out .= "<input type=\"hidden\" name=\"poll_id\" value=\"".$poll["unpacked_id"]."\">\n";
        $out.= "<input type=\"submit\" name=\"submit\" value=\""._m("Submit")."\">";
    } else {
        $out = "";
    }
    return $out;
}

function parseDesignAliases($format, $poll, $polldata, $polloption) {
    global $aliases;

    $pieces = explode("_#", $format);
    reset($pieces);
    $out = current($pieces);   // initial sequence

    while ( $vparam = next($pieces) ) {
        //search for alias definition (fce,param,hlp)
            $fnc = $aliases["_#".substr($vparam,0,8)]["fnc"];
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


function getValuesForPoll($poll_id, $show) {
    global $db, $OPTIMIZE_FOR_MYSQL;

    if ( !$show )
      return false;

    $SQL = "SELECT * FROM polls WHERE ((id='".  q_pack_id($poll_id) ."') AND (pollID='".$show."'))";
    $db->query($SQL);
    if ( !$db->next_record())
        return false;
    $poll = $db->Record;
    if ( ($poll["status_code"] != '1') OR ($poll["endDate"] < $now) )
        $poll["dont_vote"] = 1;      // do not vote in expired
                                     // or trashed poll

    $poll["unpacked_id"] = unpack_id($poll["id"]);

    $SQL = "SELECT *
           FROM polls_designs WHERE ((pollsModuleID='". q_pack_id($poll_id)."') AND (designID = '".$poll["designID"]."'))";
    $db->query($SQL);
    if ($db->next_record()) {
        $formats = $db->Record;
        $poll["format"] = $formats;
    }

    $SQL = "SELECT * FROM polls_data WHERE (pollID='".$poll["pollID"]."')";
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

function translateRelId($poll_id, $rel) {
    global $db;

    $now = now();

    $SQL = "SELECT * FROM polls
             WHERE ((id='". q_pack_id($poll_id) ."')
                   AND (status_code = '1'))
                   ORDER BY startDate";
    $db->query($SQL);
    $counter = 0;
    $zero_pos = -1;                          // pointer to current poll (rel=0)
    while ($db->next_record()) {
        if ( ($zero_pos == -1)  AND           // not set yet
            ($db->f('endDate') >= $now) AND
            ($db->f('startDate') <= $now) )
            $zero_pos = $counter;            // mark id which is rel=0
        $dummy[$counter++] = $db->f('pollID');
    }

    return( $dummy[$zero_pos + (int)$rel] );  // returns false if it is not valid
                                             // index
}


?>
