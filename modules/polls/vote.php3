<?php

// little script for registering vote.
// used as workaround for setting cookies because while the script
// is SSI included to html, we can't set cookies!

require_once "../../include/config.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."mgettext.php3";
require_once "showutils.php3";
require_once "util.php3";

if ($debug) {
  echo "<pre>";
  echo "vote: $vote poll_id: $poll_id redir: $redir";
  print_r($GLOBALS);
  echo "</pre>";
}

$encap = ( ($encap=="false") ? false : true );

if ($encap){require_once AA_INC_PATH."locsessi.php3";}
else {require_once AA_INC_PATH."locsess.php3";}

$db = new DB_AA;

  if (isset($vote) && isset($poll_id) && isset($redir)) {
    $poll = getValuesForPoll($id, $poll_id);
    registerVote($poll, $vote);
    header("Location: ".$redir);
  } else {
    echo _m("Illegal vote !!!");
    exit;
  }
?>
