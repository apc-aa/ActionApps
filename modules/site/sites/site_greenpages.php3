<?php
# site definition file for Site Example (http://apc-aa.sf.net/site/index.shtml)
# This is just an example of site file. For more details on sites see FAQ:
# http://apc-aa.sourceforge.net/faq/
# http://apc-aa.sourceforge.net/slices

# This is intended as the start of a generic, reusable site file
if ($debug) set_time_limit(240);  #Debugging can extend process time a lot

# Site specific configuration

$apc_varnames = "tpmiuvw"; //TODO replace with "tpmih*"
$apc_reg = "^([-p])([-]|[0-9]+)([hbsfcCt])([-]|[0-9]+)([hbsfcCt][-0-9]+)*";
$apc_init = '--h-s-';

#Slightly older version

$apc_state = ModW_str2arr($apc_varnames, $apc, $apc_init, $apc_reg);

if (isset($site_id)) $apc_state['site_id'] = $site_id;  # Make site_id available:

if ($m) {
	$apc_state['w'] = $apc_state['v'];
	$apc_state['v'] = $apc_state['u'];
	$apc_state['u'] = $apc_state['m'] . $apc_state['i'];
	$apc_state['m'] = $m;
	$apc_state['p'] = '1';
}

if (isset($p)) $apc_state = array_merge($apc_state, array('p' => $p, 'x' => '-')); #page
if (isset($t)) $apc_state['t'] = $t; #Switch to special mode (text only, print ...)
if (isset($i)) $apc_state['i'] = $i; #item id to display

if( isset($scrl) ) {      # page scroller
  $pagevar = "scr_".$scrl."_Go";
  $apc_state['p'] = $$pagevar;
}

if( ($apc_state["p"] <= 0) OR ($apc_state["p"]=='-') )
  $apc_state["p"] = 1;

$apc_state['state'] =  ModW_arr2str($apc_varnames,$apc_state);
$apc_state["site_url"] = $AA_INSTAL_EDIT_PATH . "modules/site/site.php3?site_id=$site_id"
	. "&apc=" . $apc_state['state'] 
	. ($nocache ? "&nocache=1" : "") . ($debug ? "&debug=1" : "")
;
$apc_state["item"] = ModW_id2item($apc_state["i"],true);

if ($debug) { print("<pre>New State="); print_r($apc_state); print("</pre>"); }
 print("<listing>New State="); print_r($apc_state); print("</listing>"); 

// list of all slices used in site. Site module uses cache. If you change the slice
// (you add item, ...), the cache should be cleared for this slice, in order site
// show the newest slice content. In $slice4cache array you will tell the slice
// module, which slices it should take care about
$slices4cache = array( 
		"5a8fc21be3fdb898d3ba77b62ac1a613", //GP business2flag
		"0ec747ad42babf093e92e6bb9853e5e2", //GP businesses
		"43cae042188b8d094044da804497ba3b", //GP Category
		"aa1e51ff4e4e955386296cf7c3ea87df", //GP Flags
		"0d9bd7ff5e62515e6fd432806fda8c6a", //GP Stories
		"93075b63f842973ab40859a2104765a8"); //GP Site

// You can define macros here, these can include any of the { ...} syntaxes
$als[editme] = '<a href="_#EDITITEM"><img src="' . $AA_INSTAL_PATH . 'images/edit.gif"></a>'; #Use {editme}
$als[additem] = '<a href="{alias::f_e:add}"><img src="' . $AA_INSTAL_PATH . 'images/add.gif"></a>'; #Use {additem}

?>


