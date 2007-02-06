<?php

$aliases["_#POLLQUES"] = array( "fnc" => "showQuestion",
                                "hlp" => _m("Prints poll question"));

$aliases["_#POLLANSW"] = array( "fnc" => "showAnswer",
                                "hlp" => _m("Prints poll answer"));

$aliases["_#BAR_RES#"] = array( "fnc" => "showBar",
                                "hlp" => _m("Shows bar image"));

$aliases["_#POLLVTS#"] = array( "fnc" => "showAnswerVotes",
                                "hlp" => _m("Shows number of voters of this answer"));

$aliases["_#ALLVOTES"] = array( "fnc" => "showNumAllVotes",
                                "hlp" => _m("Shows number of all voters"));

$aliases["_#POLLPERC"] = array( "fnc" => "showAnswerPercents",
                                "hlp" => _m("Shows percentage of voters of this answer"));

$aliases["_#POLL_ID#"] = array( "fnc" => "showID",
                                "hlp" => _m("alias for Poll ID"));

$aliases["_#BAR_VOTE"] = array( "fnc" => "printBarForVote",
                                "hlp" => _m("prints bar, whitch is used for voting (after click)"));

$aliases["_#RADIOVOT"] = array( "fnc" => "printRadioButton",
                                "hlp" => _m("prints radiobutton, used for voting"));

$aliases["_#SENDBTN#"] = array( "fnc" => "printSendButton",
                                "hlp" => _m("prints Send button"));
                                
$aliases["_#VOTENUM#"] = array( "fnc" => "printAnswNumber",
                                "hlp" => _m("prints number of answer"));                                

$colornames = array (
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
