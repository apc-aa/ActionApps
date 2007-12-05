<?php

/** Predefined aliases for polls. For another aliases use 'inline' aliases. */
function GetPollsAliases() {  // function - we need trnslate _m() on use (not at include time)
    $metabase = AA_Metabase::singleton();
    $aliases = array (
        "_#POLL_ID_" => GetAliasDef( "f_t",               "id",          _m('Poll ID')),
        "_#QUESTION" => GetAliasDef( "f_t",               "headline",       _m('Prints poll question')),
        "_#PUB_DATE" => GetAliasDef( "f_d:n/j/Y",         "publish_date",       _m('Publish Date')),
        "_#EXP_DATE" => GetAliasDef( "f_d:n/j/Y",         "expiry_date",         _m('Expiry Date')),
        "_#EDITPOLL" => GetAliasDef( "f_e:poll_edit",     "id",          _m('Link to poll editing page (for admin interface only)')),
        "_#ANSWER__" => GetAliasDef( "showAnswer",        "",                _m('Prints poll answer')),
        "_#POLLQUES" => GetAliasDef( "f_t:{ifset:1:2:3}",        "",                _m('Prints poll answer')),
        "_#POLLANSW" => GetAliasDef( "showAnswer",         '', _m("Prints poll answer")),
        "_#BAR_RES#" => GetAliasDef( "showBar",            '', _m("Shows bar image")),
        "_#POLLVTS#" => GetAliasDef( "showAnswerVotes",    '', _m("Shows number of voters of this answer")),
        "_#ALLVOTES" => GetAliasDef( "showNumAllVotes",    '', _m("Shows number of all voters")),
        "_#POLLPERC" => GetAliasDef( "showAnswerPercents", '', _m("Shows percentage of voters of this answer")),
        "_#POLL_ID#" => GetAliasDef( "showID",             '', _m("alias for Poll ID")),
        "_#BAR_VOTE" => GetAliasDef( "printBarForVote",    '', _m("prints bar, whitch is used for voting (after click)")),
        "_#RADIOVOT" => GetAliasDef( "printRadioButton",   '', _m("prints radiobutton, used for voting")),
        "_#SENDBTN#" => GetAliasDef( "printSendButton",    '', _m("prints Send button")),
        "_#VOTENUM#" => GetAliasDef( "printAnswNumber",    '', _m("prints number of answer")),
    );
    return array_merge($metabase->generateAliases('polls'), $aliases);
}

$COLORNAMES = array (
  "black"  => "#000000",
  "silver" => "#C0C0C0",
  "gray"   => "#808080",
  "white"  => "#FFFFFF",
  "maroon" => "#800000",
  "red"    => "#FF0000",
  "purple" => "#800080",
  "fuchsia"=> "#FF00FF",
  "green"  => "#008000",
  "lime"   => "#00FF00",
  "olive"  => "#808000",
  "yellow" => "#FFFF00",
  "navy"   => "#000080",
  "blue"   => "#0000FF",
  "teal"   => "#008080",
  "aqua"   => "#00FFFF");

$POLL_PARAMS = array ("name"=>_m("Polls parameters"),
"items"=>array(
"pol"=>array("name"=>L_PARAM_WIZARD_INPUT_pol_NAME,
    "desc"=>L_PARAM_WIZARD_INPUT_pol_DESC,
    "params"=>array(
        array("name"=>L_PARAM_WIZARD_INPUT_pol_PAR0_NAME,
        "desc"=>L_PARAM_WIZARD_INPUT_pol_PAR0_DESC,
        "type"=>"INT",
        "example"=>L_PARAM_WIZARD_INPUT_pol_PAR0_EXAMPLE)
))));

?>
