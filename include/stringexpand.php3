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
#                         itemview class
#
# Note that this is NOT defined as a class, and is called within several other classes
# ----------------------------------------------------------------------------

# Code by Mitra based on code in existing other files

if (!defined ("STRINGEXPAND_INCLUDED")) 
     define ("STRINGEXPAND_INCLUDED",1);
else return;


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
    $twos = ParamExplode( strtok("") );
    $i=0;
    while( $i < count($twos) ) {
      if( $i == (count($twos)-1)) {                # default option
        return $twos[$i];
      }
      $val = trim($twos[$i]);
      if( !$val OR ereg($val, $variable) ) {    # Note that variable, might be expanded {headline.......} or {m}
        return $twos[$i+1];
      }
      $i+=2;
    }
    return "";
  }

  function parseMath($text)
  {
  	$variable = strtok($text,")");
    $twos = ParamExplode( strtok("") );
	//print_r ($twos);
	$i=0;$key=true;
	while( $i < count($twos) ) {
     $val = trim($twos[$i]);
	
	  if ($key)
	  	{
			if ($val) $ret.=str_replace("#:","",$val); $key=false;
		}
	  else
	  	{	$val=str_replace ("{", "", $val);
			$val=str_replace ("}", "", $val);
            $val = calculate ($val); // defined in math.php3
			
			$format=explode("#",$variable);
			$val=number_format($val, $format[0], $format[1], $format[2]);
			
			$ret.=$val;
			$key=true;
		}
	 $i++;
    }
  	return $ret;
  }
    
# Substitutes all colons with special AA string and back depending on unalias nesting.
# Used to mark colons, which is not parameter separators.
# Note that this function is duplicated in ModW_QuoteColons
function QuoteColons($level, $maxlevel, $text) {
  if( $level > 0 )                  # there is no need to substitute on level 1
	return str_replace(":", "_AA_CoLoN_", 
		str_replace("{", "_AA_OpEnBrAcE_",
		str_replace("}", "_AA_ClOsEbRaCe_",$text)));

  #level 0 - return from unalias - change all back to ':'
  if( ($level == 0) AND ($maxlevel > 0) )  # maxlevel - just for speed optimalization
    return str_replace("_AA_CoLoN_", ":", 
		str_replace("_AA_OpEnBrAcE_","{",
		str_replace("_AA_ClOsEbRaCe_","}", $text)));
  return $text;
}

# Substitutes special AA 'colon' string back to colon ':' character
# Used for parameters, where is no need colons are not parameter separators
function DeQuoteColons($text) {
  return str_replace("_AA_CoLoN_", ":", 
		str_replace("_AA_OpEnBrAcE_","{",
		str_replace("_AA_ClOsEbRaCe_", "}", $text)));
}


# Expand a single, syntax element.
function expand_bracketed(&$out,$level,&$maxlevel,$item,$itemview,$aliases) {

    global $als;
    $maxlevel = max($maxlevel, $level); # stores maximum deep of nesting {}
                                        # used just for speed optimalization (QuoteColons)
    # bracket could look like:
    #   {alias:[<field id>]:<f_* function>[:parameters]} - return result of f_*
    #   {<field_id>}                                     - return content of field
    #   {any text}                                       - return "any text"
    # all parameters could contain aliases (like "{any _#HEADLINE text}"),
    # which is processed first (see above)
    if( isset($item) && ereg("^alias:([^:]*):([a-zA-Z0-9_]{1,3}):(.*)$", $out, $parts) ) {
      # call function (called by function reference (pointer))
      # like f_d("start_date......", "m-d")
      $fce = $parts[2];
      return QuoteColons($level, $maxlevel, $item->$fce($parts[1], $parts[3]));
      # QuoteColons used to mark colons, which is not parameter separators.
    }
    elseif( substr($out, 0, 7) == "switch(" ) {
      # replace switches
      return QuoteColons($level, $maxlevel, parseSwitch( substr($out,7) ));
      # QuoteColons used to mark colons, which is not parameter separators.
	  }
    elseif( substr($out, 0, 5) == "math(" ) { #TODO REMOVE item
      # replace math
      return QuoteColons($level, $maxlevel, $item->parseMath( substr($out,5) ));
      # QuoteColons used to mark colons, which is not parameter separators.  
	  }
    elseif( substr($out, 0, 8) == "include(" ) {
      # include file
      if( !($pos = strpos($out,')')) )
        return "";
      $filename = str_replace( 'URL_PARAMETERS', DeBackslash(shtml_query_string()), 
                               DeQuoteColons( substr($out, 8, $pos-8)));
           # filename do not use colons as separators => dequote before callig
           
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
    if( ereg("^scroller:?([^}]*)$", $txt, $parts)) {
	if (!isset($itemview) OR ($itemview->num_records<0) ) {   #negative is for n-th grou display
		return "Scroller not valid without a view, or for group display"; }
	$viewScr = new view_scroller($itemview->slice_info['vid'],
                                   $itemview->clean_url,
                                   $itemview->num_records,
                                   count($itemview->ids),
                                   $itemview->from_record);
	list( $begin, $end, $add, $nopage ) = ParamExplode($parts[1]);                         
	return $viewScr->get( $begin, $end, $add, $nopage );
    }
    elseif( substr($out, 0, 1) == "#" )
      # remove comments
      return "";
    elseif( substr($out, 0,5) == "debug" ) {
	# Note don't rely on the behavior of {debug} its changed by programmers for testing!
	print("<listing>");
        if (isset($item)) huhl("item=",$item);
	if (isset($GLOBALS["apc_state"])) huhl("apc_state=",$GLOBALS["apc_state"]);
	if (isset($itemview)) huhl("itemview=",$itemview);
	if (isset($aliases)) huhl("aliases=",$aliases);
	if (isset($als)) huhl("als=",$als);
	#huhl("globals=",$GLOBALS);
	print("</listing><br>"); 
    }
    elseif ( substr($out, 0,10) == "view.php3?" )
	return QuoteColons($level, $maxlevel, 
            GetView(ParseViewParameters(DeQuoteColons(substr($out,10) ))));
            # view do not use colons as separators => dequote before callig
    elseif( isset($item) && IsField($out) ) {
      return QuoteColons($level, $maxlevel, $item->getval($out));
      # QuoteColons used to mark colons, which is not parameter separators.
    }
    # Look and see if its in the state variable in module site
    # note, this is ignored if apc_state isn't set, i.e. not in that module
    elseif (isset($GLOBALS['apc_state'][$out])) {
	return QuoteColons($level, $maxlevel, $GLOBALS['apc_state'][$out]);
    }
    # Pass these in URLs like als[foo]=bar, 
    # Note that 8 char aliases like als[foo12345] will expand with _#foo12345
    elseif (isset($als[$out])) {
	return QuoteColons($level, $maxlevel, $als[$out]);
    }
    elseif (isset($aliases[$out])) {   # look for an alias (this is used by mail)
	return QuoteColons($level, $maxlevel, $aliases[$out]);
    }  // Put the braces back around the text and quote them if we can't match it
    else {
	if ($debug)  huhl("Couldn't expand: \"{$out}\"");
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
    while (ereg("(.*)[{]([^{}]+)[}](.*)",$text,$vars)) {
	if ($debug) print("<listing>Expanding:$level:'$vars[2]'</listing>");
	$t1 = expand_bracketed($vars[2],$level+1,$maxlevel,$item,$itemview,$aliases);
	if ($debug) print("<listing>Expanded:$level:'$t1'</listing>");
	$text = $vars[1] . $t1 . $vars[3];
        if ($debug) print("<listing>Continue with:$level:'$text'</listing>");
    }
    if (isset($item)) {
	    return QuoteColons($level, $maxlevel, $item->substitute_alias_and_remove($text,explode ("##",$remove)));
    } else {
	    return QuoteColons($level, $maxlevel, $text);
    }
}


