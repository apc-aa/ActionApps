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

function getmicrotime(){ 
  list($usec, $sec) = explode(" ",microtime()); 
  return ((float)$usec + (float)$sec); 
} 

$timestart = getmicrotime();

# APC AA site Module main administration page
require_once "../../include/config.php3";
require_once $GLOBALS["AA_INC_PATH"]."locsess.php3";
require_once $GLOBALS["AA_INC_PATH"]."util.php3"; 
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3"; 
require_once $GLOBALS["AA_INC_PATH"]."stringexpand.php3";
require_once $GLOBALS["AA_INC_PATH"]."item.php3"; # So site_ can create an item

function IsInDomain( $domain ) {
  global $HTTP_HOST;
  return (($HTTP_HOST == $domain)  || ($HTTP_HOST == 'www.'.$domain));
}  

# ----------------- function definition end -----------------------------------

$db = new DB_AA;
$err["Init"] = "";          # error array (Init - just for initializing variable

# change the state
add_vars();                 # get variables pased to stm page

$site_info = GetModuleInfo($site_id,'W');   # W is identifier of "site" module
                                            #    - see /include/constants.php3

if( !$site_info['state_file'] ) {
  echo "<br>Error: no 'state_file' defined";
  exit;
}

if( substr($site_info['state_file'],0,4) == 'http' ) {
  echo "TODO";
} else {
  # in the following file we should define apc_state variable
  require_once "./sites/site_".$site_info['state_file'];   
}

# look into cache if the page is not cached

# CACHE_TTL defines the time in seconds the page will be stored in cache
# (Time To Live) - in fact it can be infinity because of automatic cache 
# flushing on page change
# CACHE_PURGE_FREQ - frequency in which the cache is checked for old values 
#                    (in seconds)

# create keystring from values, which exactly identifies resulting content
$key_str = $apc_state['state'];
if( is_array($slices4cache) && !$nocache && ($res = $GLOBALS[pagecache]->get($key_str)) ) {
  echo $res;
  if( $debug ) {
    $timeend = getmicrotime();
    $time = $timeend - $timestart;
    echo "<br><br>Site cache hit!!! Page generation time: $time";
  }  
  exit;
} 

require_once "./util.php3";                      # module specific utils
require_once "./sitetree.php3";                  # module specific utils
require_once $GLOBALS["AA_INC_PATH"]."searchlib.php3"; 
require_once $GLOBALS["AA_INC_PATH"]."easy_scroller.php3";
require_once $GLOBALS["AA_INC_PATH"]."view.php3";
require_once $GLOBALS["AA_INC_PATH"]."discussion.php3";
require_once $GLOBALS["AA_INC_PATH"]."item.php3"; 
  
$res = ModW_GetSite( $apc_state, $site_id, $site_info );
echo $res;

# In $slices4cache array MUST be listed all (unpacked) slice ids (and other 
# modules including site module itself), which is used in the site. If you 
# mention the slice in this array, cache is cleared on any change of the slice
# (item addition) - the page is regenerated, then.

if (is_array($slices4cache) && !$nocache) {
  $clear_cache_str = "slice_id=". join(',slice_id=', $slices4cache);
  $GLOBALS[pagecache]->store($key_str, $res, $clear_cache_str);
}  

if( $debug ) {
  $timeend = getmicrotime();
  $time = $timeend - $timestart;
  echo "<br><br>Page generation time: $time";
}  

# ----------------- process status end ----------------------------------------

function ModW_GetSite( $apc_state, $site_id, $site_info ) {
  global $db, $show_ids;

  # site_id should be defined as url parameter
  $module_id = $site_id;
  $p_module_id = q_pack_id($module_id);
  
  $tree = new sitetree();
  $tree = unserialize($site_info['structure']);
  
  $show_ids = array(); 

  # it fills $show_ids array
  $tree->walkTree($apc_state, 1, 'ModW_StoreIDs', 'cond');
  if(count($show_ids)<1)
    exit;

  $in_ids = implode( $show_ids, ',' );
  
  # get contents to show
  $SQL = "SELECT spot_id, content, flag from site_spot 
           WHERE site_id='$p_module_id' AND spot_id IN ($in_ids)";
  $db->query($SQL);
  while( $db->next_record() ) {
    $contents[$db->f('spot_id')] = $db->f('content');
    $flags[$db->f('spot_id')] = $db->f('flag');
  }
  
  reset($show_ids);
  while( list(,$v) = each($show_ids)) {
    $spot_content = $contents[$v];
    $out .= ( ($flags[$v] & MODW_FLAG_JUST_TEXT) ? $spot_content
                                              : ModW_unalias($spot_content, $apc_state) );
  }                                            
  return $out;
}                                                
  
function ModW_StoreIDs($spot_id, $depth) {
  $GLOBALS['show_ids'][] = $spot_id;
}  
/* Deprecated - uses code in stringexpand.php3}
function ModW_ParseSwitch($text, &$state) {
  $variable = strtok($text,")");
  $twos = ParamExplode( strtok("") );


  global $debug;
  if ( $debug == 2 ) {
    print_r($twos);
  }

  $i=0;
  while( $i < count($twos) ) {
    $val = trim($twos[$i]);
    if( !$val OR ereg($val, $state[$variable]) )
      return $twos[$i+1];
    $i+=2;
  }
  return "";
}
*/
/* Deprecated uses stringexpand.php3 
# See include/item.php3 for other places that {...} syntax is used, 
function ModW_unalias_recurent(&$text, &$state, $level, &$maxlevel) {

  $maxlevel = max($level, $maxlevel);       # just for speed optimalization (ModW_QuoteColons)

  $pos = strcspn( $text, "{}" );
  while( $text[$pos] == '{' ) {
    $out .= substr( $text,0,$pos );         # initial sequence
    $text = substr( $text,$pos+1 );         # remove processed text
    $out .= ModW_unalias_recurent( $text, $state, $level+1, $maxlevel ); # from $text is removed {...} on return
    $pos = strcspn( $text, "{}" );          # process next bracket (in text: "...{..}..{.}..")
  }
  $out .= substr( $text,0,$pos );           # end sequence
  $text = substr( $text,$pos+1 );           # remove processed text
  
  # now we know, there is no bracket in $out - we can substitute

  # bracket could look like:
  #   {switch(var1,var2)val1,val2:<printed text>:
  #                     val1,val2:<printed text>}   - return text based on condition (reguler expression)
  #   {view.php3?vid=<vid>&<view parameters>}       - return view
  #   {<variable>}                                  - return content of variable
  #   {any text}                                    - return "any text"

  # replace all variable aliases
  if( substr($out, 0, 5) == "debug" ) {
	print_r($state);
	return "";
  }
  if( (strlen($out)<=32) AND isset($state) AND is_array($state) AND isset($state[$out]) )
    return ModW_QuoteColons($level, $maxlevel, $state[$out]);
  # replace switches
  if( substr($out, 0, 7) == "switch(" )
    return ModW_QuoteColons($level, $maxlevel, ModW_ParseSwitch( substr($out,7), $state ));
  # remove comments
  if( substr($out, 0, 1) == "#" )
    return "";
  # replace views
  if( substr($out, 0, 10) == "view.php3?" )
    return ModW_QuoteColons($level, $maxlevel, 
            GetView(ParseViewParameters(ModW_DeQuoteColons(substr($out,10) ))));
            # view do not use colons as separators => dequote before callig
  # else just print text
  return ModW_QuoteColons($level, $maxlevel, ($level>0) ? '{'.$out.'}' : $out);
}
*/
function ModW_unalias( &$text, &$state ) {
  // just create variables and set initial values
  $maxlevel = 0;   
  $level = 0;
  return new_unalias_recurent($text, "", $level, $maxlevel,$state[item]);
#  return ModW_unalias_recurent( $text, $state, $level, $maxlevel );
}

// id = an item id, unpacked or short
// short_ids = boolean indicating type of $ids (default is false => unpacked)
function ModW_id2item($id,$use_short_ids="false") {
   if (isset($id) && ($id != "-")) {
        $content = GetItemContent($id, $use_short_ids);
        $slice_id = unpack_id128($content[$id]["slice_id........"][0][value]);
        list($fields,) = GetSliceFields($slice_id);
        $aliases = GetAliasesFromFields($fields);
        return new item("",$content[$id],$aliases,"","","");
    }
}

# Convert a state string into an array, based on the variable names and 
# regular expression supplied, if str is not present or doesn't match 
# the regular expression then use $strdef
# e.g. ModW_str2arr("tpmi",$apc,"--h-",
	"^([-p])([-]|[0-9]+)([hbsfcCt])([-]|[0-9]+)";
function ModW_str2arr($varnames, $str, $strdef, $reg) {
	if (!$str) $str = $strdef;
	$varout = array();
	if (!(ereg($reg, $str, $vars))) 
		if (!(ereg($reg, $strdef, $vars))) {
			print("Error initial string $strdef doesn't match regexp $reg\n<br>");
		}
	for ($i=0;$i < min(strlen($varnames),count($vars)-1); ++$i) {
		$varout[substr($varnames,$i,1)] = $vars[$i+1];
	}
	if ($debug) { print("<br>State="); print_r($varout); }
	return $varout;
}

# Convert an array into a state string, in the order from $varnames
# This is fairly simplistic, just concatennating state, a more 
# sophisticated sprint version might be needed
function ModW_arr2str($varnames, $arr) {
	$strout = "";
	for ($i=0; $i < strlen($varnames); ++$i) {
		$strout .= $arr[substr($varnames,$i,1)];
	}
	return $strout;
}


exit;

?>
