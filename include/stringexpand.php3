<?php
//$Id$
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

# ----------------------------------------------------------------------------
#                         stringexpand
#
# Note that this is NOT defined as a class, and is called within several other classes
# ----------------------------------------------------------------------------

# Code by Mitra based on code in existing other files

if (!defined ("STRINGEXPAND_INCLUDED"))
     define ("STRINGEXPAND_INCLUDED",1);
else return;

require_once $GLOBALS["AA_INC_PATH"]."easy_scroller.php3";

#explodes $param by ":". The "#:" means true ":" - don't separate
function ParamExplode($param) {
  $a = str_replace ("#:", "__-__.", $param);    # dummy string
  $b = str_replace ("://", "__-__2", $a);       # replace all <http>:// too
  $c = str_replace (":", "##Sx",$b);            # Separation string is ##Sx
  $d = str_replace ("__-__.", ":", $c);         # change "#:" to ":"
  $e = str_replace ("__-__2", "://", $d);         # change back "://"
  return explode( "##Sx", $e );
}


  function parseSwitch($text) {
    $variable = substr(strtok('_'.$text,")"),1);   # add and remove '_' - this
                                                   # is hack for empty variable
                                                   # (when $text begins with ')')
    $variable = DeQuoteColons($variable);	# If expanded, will be quoted ()
    $twos = ParamExplode( strtok("") );
    $i=0;
    while( $i < count($twos) ) {
      if( $i == (count($twos)-1)) {                # default option
        return $twos[$i];
      }
      $val = trim($twos[$i]);
      # Note you can't use !$val, since this will match a pattern of exactly "0"
      if( ($val=="") OR ereg($val, $variable) ) {    # Note that variable, might be expanded {headline.......} or {m}
        return $twos[$i+1];
      }
      $i+=2;
    }
    return "";
  }

  # text = [ decimals [ # dec_point [ thousands_sep ]]] )
  function parseMath($text)
  {
    // get format string, need to add and remove # to
    // allow for empty string
  	$variable = substr(strtok("#".$text,")"),1);
    $twos = ParamExplode( strtok("") );
	$i=0;
    $key=true;
	while( $i < count($twos) ) {
     $val = trim($twos[$i]);
	  if ($key)
	  	{
			if ($val) $ret.=str_replace("#:","",$val); $key=false;
		}
	  	else {	#$val=str_replace ("{", "", $val);
			#$val=str_replace ("}", "", $val);
            $val = calculate ($val); // defined in math.php3
            if ($variable) {
    			$format=explode("#",$variable);
	    		$val = number_format($val, $format[0], $format[1], $format[2]);
            }
			$ret.=$val;
			$key=true;
		}
	 $i++;
    }
  	return $ret;
  }

# Do not change strings used, as they can be used to force an escaped character
# in something that would normally expand it
$QuoteArray = array(":" => "_AA_CoLoN_",
		"(" => "_AA_OpEnPaR_", ")" => "_AA_ClOsEpAr_",
		"{" => "_AA_OpEnBrAcE_", "}" => "_AA_ClOsEbRaCe_");
$UnQuoteArray = array_flip($QuoteArray);


# Substitutes all colons with special AA string and back depending on unalias nesting.
# Used to mark characters :{}() which are content, not syntax elements
function QuoteColons($level, $maxlevel, $text) {
  global $QuoteArray, $UnQuoteArray; # Global so not built at each call
  if( $level > 0 )                  # there is no need to substitute on level 1
	return strtr($text, $QuoteArray);

  #level 0 - return from unalias - change all back to ':'
  if( ($level == 0) AND ($maxlevel > 0) )  # maxlevel - just for speed optimalization
	return strtr($text, $UnQuoteArray);
  return $text;
}

# Substitutes special AA 'colon' string back to colon ':' character
# Used for parameters, where is no need colons are not parameter separators
function DeQuoteColons($text) {
	global $UnQuoteArray;
	return strtr($text, $UnQuoteArray);
}

// In this array are set functions from PHP or elsewhere that can usefully go in {xxx:yyy:zzz} syntax
$GLOBALS[eb_functions] = array (
    fmod => fmod    # php > 4.2.0
);

# Expand a single, syntax element.
function expand_bracketed(&$out,$level,&$maxlevel,$item,$itemview,$aliases) {

    global $als,$debug,$errcheck;

    $maxlevel = max($maxlevel, $level); # stores maximum deep of nesting {}
                                        # used just for speed optimalization (QuoteColons)
    # See http://apc-aa.sourceforge.net/faq#aliases for details
    # bracket could look like:
    # {alias:[<field id>]:<f_* function>[:parameters]} - return result of f_*
    # {switch(testvalue)test:result:test2:result2:default}
    # {math(<format>)expression}
    # {include(file)}
    # {scroller.....}
    # {#comments}
    # {debug}
    # {view.php3?vid=12&cmd[12]=x-12-34}
    # {dequote:already expanded and quoted string}
    # {fnctn:xxx:yyyy}   - expand $eb_functions[fnctn]
    # {unpacked_id.....}
    # {xxxx}
    #   - looks for a field xxxx
    #   - or in $GLOBALS[apc_state][xxxx]
    #   - als[xxxx]
    #   - aliases[xxxx]
    # {_#ABCDEFGH}
    #   {any text}                                       - return "any text"
    # all parameters could contain aliases (like "{any _#HEADLINE text}"),
    # which are processed before expanding the function
    if( isset($item) && (substr($out, 0, 5)=='alias') AND ereg("^alias:([^:]*):([a-zA-Z0-9_]{1,3}):(.*)$", $out, $parts) ) {
      # call function (called by function reference (pointer))
      # like f_d("start_date......", "m-d")
      if ($parts[1] && ! isField($parts[1]))
        huhe("Warning: $out: $parts[1] is not a field, don't wrap it in { } ");
      $fce = $parts[2];
      return QuoteColons($level, $maxlevel, $item->$fce($parts[1], $parts[3]));
      # QuoteColons used to mark colons, which is not parameter separators.
    }
    elseif( substr($out, 0, 7) == "switch(" ) {
      # replace switches
      return QuoteColons($level, $maxlevel, parseSwitch( substr($out,7) ));
      # QuoteColons used to mark colons, which is not parameter separators.
          }
    elseif( substr($out, 0, 5) == "math(" ) {
      # replace math
      return QuoteColons($level, $maxlevel,
        parseMath( # Need to unalias in case expression contains _#XXX or ( )
            new_unalias_recurent(substr($out,5),"",0,
                        $maxlevel,$item,$itemview,$aliases)) );

          }
    elseif( substr($out, 0, 8) == "include(" ) {
      # include file
      if( !($pos = strpos($out,')')) )
        return "";
      $filename = str_replace( 'URL_PARAMETERS', DeBackslash(shtml_query_string()),
                               DeQuoteColons( substr($out, 8, $pos-8)));
           # filename do not use colons as separators => dequote before callig

      if( !$filename || trim($filename)=="" )
        return "";

      // if no http request - add server name
      if( !(substr($filename, 0, 7) == 'http://') AND
          !(substr($filename, 0, 8) == 'https://')   )
        $filename = self_server().'/'. $filename;

      $fp = @fopen( $filename, 'r' );
      $fileout = fread( $fp, defined("INCLUDE_FILE_MAX_SIZE") ? INCLUDE_FILE_MAX_SIZE : 400000 );
      fclose( $fp );
      return QuoteColons($level, $maxlevel, $fileout);
      # QuoteColons used to mark colons, which is not parameter separators.
    }
    elseif( ereg("^scroller:?([^}]*)$", $out, $parts)) {
        if (!isset($itemview) OR ($itemview->num_records<0) ) {   #negative is for n-th grou display
                return "Scroller not valid without a view, or for group display"; }
        $viewScr = new view_scroller($itemview->slice_info['vid'],
                                   $itemview->clean_url,
                                   $itemview->num_records,
                                   $itemview->idcount(),
                                   $itemview->from_record);
        list( $begin, $end, $add, $nopage ) = ParamExplode($parts[1]);
        return $viewScr->get( $begin, $end, $add, $nopage );
    }
    elseif( substr($out, 0, 1) == "#" )
      # remove comments
      return "";
    elseif( substr($out, 0,5) == "debug" ) {
        # Note don't rely on the behavior of {debug} its changed by programmers for testing!
        if (isset($item)) huhl("item=",$item);
        if (isset($GLOBALS["apc_state"])) huhl("apc_state=",$GLOBALS["apc_state"]);
        if (isset($itemview)) huhl("itemview=",$itemview);
        if (isset($aliases)) huhl("aliases=",$aliases);
        if (isset($als)) huhl("als=",$als);
        #huhl("globals=",$GLOBALS);
        return "";
    }
    elseif ( substr($out, 0,10) == "view.php3?" )
        return QuoteColons($level, $maxlevel,
            GetView(ParseViewParameters(DeQuoteColons(substr($out,10) ))));
            # view do not use colons as separators => dequote before callig
    // This is a little hack to enable a field to contain expandable { ... } functions
    // if you don't use this then the field will be quoted to protect syntactical characters
    elseif( substr($out, 0, 8) == "dequote:" ) {
        return DeQuoteColons(substr($out,8));
    }
    // OK - its not a known fixed string, look in various places for the whole string
    if( ereg("^([a-zA-Z_0-9]+):?([^}]*)$", $out, $parts) && $GLOBALS[eb_functions][$parts[1]]) {
        $fnctn = $GLOBALS[eb_functions][$parts[1]];
        $parts = ParamExplode($parts[2]);
        return QuoteColons($level,$maxlevel,
            $fnctn($parts[0],$parts[1],$parts[2],$parts[3],$parts[4],
                $parts[5],$parts[6],$parts[7],$parts[8],$parts[9]));
    }
    if (isset($item) && ($out == "unpacked_id.....")) {
        return QuoteColons($level, $maxlevel, $item->f_n('id..............'));
    }
    elseif( isset($item) && IsField($out) ) {
//      return QuoteColons($level, $maxlevel, $item->getval($out));
      return QuoteColons($level, $maxlevel, $item->f_h($out,"-"));
      # QuoteColons used to mark colons, which is not parameter separators.
    }
    # Look and see if its in the state variable in module site
    # note, this is ignored if apc_state isn't set, i.e. not in that module
    # If found, unalias the value, then quote it, this expands
    # anything inside the value, and then makes sure any remaining quotes
    # don't interfere with caller
    elseif (isset($GLOBALS['apc_state'][$out])) {
        return QuoteColons($level, $maxlevel,
            new_unalias_recurent($GLOBALS['apc_state'][$out],"",$level+1,
                $maxlevel,$item,$itemview,$aliases));
    }
    # Pass these in URLs like als[foo]=bar,
    # Note that 8 char aliases like als[foo12345] will expand with _#foo12345
    elseif (isset($als[$out])) {
        return QuoteColons($level, $maxlevel,
            new_unalias_recurent($als[$out],"",$level+1,
                $maxlevel,$item,$itemview,$aliases));
        return QuoteColons($level, $maxlevel, $als[$out]);
    }
    elseif (isset($aliases[$out])) {   # look for an alias (this is used by mail)
        return QuoteColons($level, $maxlevel, $aliases[$out]);
    }
    // Look for {_#.........} and expand now, rather than wait till top
    elseif ( isset($item) && (substr($out,0,2) == "_#")) {
        return $item->substitute_alias_and_remove($out);
    }
     // Put the braces back around the text and quote them if we can't match
    else {
        if ($errcheck)  huhl("Couldn't expand: \"{$out}\"");
        return QuoteColons($level, $maxlevel, "{" . $out . "}");
    }
}

# This is based on the old unalias_recurent, it is intended to replace
# string substitution wherever its occurring.
# Differences ....
#   - remove is applied to the entire result, not the parts!
function new_unalias_recurent(&$text, $remove, $level, &$maxlevel, $item=null, $itemview=null, $aliases=null ) {
    global $debug;
    $maxlevel = max($maxlevel, $level); # stores maximum deep of nesting {}
                                        # used just for speed optimalization (QuoteColons)
    if ($debug) huhl("<br>Unaliasing:$level:'",$text,"'\n");
# Note ereg was 15 seconds on one multi-line example cf .002 secs
#    while (ereg("^(.*)[{]([^{}]+)[}](.*)$",$text,$vars)) {

    while (preg_match("/^(.*)[{]([^{}]+)[}](.*)$/s",$text,$vars)) {
        if ($debug) huhl("Expanding:".isset($item).":$level:'$vars[2]'");

        $t1 = expand_bracketed($vars[2],$level+1,$maxlevel,$item,$itemview,$aliases);

        if ($debug) huhl("Expanded:$level:'$t1'");
        $text = $vars[1] . $t1 . $vars[3];
        if ($debug) huhl("Continue with:$level:'$text'");
    }

    if (isset($item)) {
            return QuoteColons($level, $maxlevel, $item->substitute_alias_and_remove($text,explode ("##",$remove)));
    } else {
            return QuoteColons($level, $maxlevel, $text);
    }
}


