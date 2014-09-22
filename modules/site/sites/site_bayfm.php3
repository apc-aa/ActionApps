<?php
// site definition file for Site Example (http://apc-aa.sf.net/site/index.shtml)
// This is just an example of site file. For more details on sites see FAQ:
// http://apc-aa.sourceforge.net/faq/
// http://apc-aa.sourceforge.net/slices

// This is intended as the start of a generic, reusable site file
if ($debug) set_time_limit(240);  //Debugging can extend process time a lot

// Site specific configuration

//t = special mode (text only, or print) (not currently used)
//p = page number (not used)
//m = major state (not used)
//i = item number (used to select page to show)
$apc_varnames = "tpmi"; //TODO replace with "tpmih*"
$apc_reg = "^([-e])([-]|[0-9]+)([-])([-]|[0-9]+)"; // ([hbsfcCt][-0-9]+)*";
$apc_init = '---26';   // Home page itemid = 10

$apc_state = ModW_str2arr($apc_varnames, $apc, $apc_init, $apc_reg);

if (isset($site_id)) $apc_state['site_id'] = $site_id;  // Make site_id available:

/* Sample code for keeping history
if ($m) {
    $apc_state['w'] = $apc_state['v'];
    $apc_state['v'] = $apc_state['u'];
    $apc_state['u'] = $apc_state['m'] . $apc_state['i'];
    $apc_state['m'] = $m;
    $apc_state['p'] = '1';
}
*/

if (isset($p)) $apc_state = array_merge($apc_state, array('p' => $p, 'x' => '-')); //page
if (isset($t)) $apc_state['t'] = $t; //Switch to special mode (text only, print ...)
if (isset($i)) $apc_state['i'] = $i; //item id to display

// Handle paging
if ( isset($scrl) ) {      // page scroller
  $pagevar = "scr_".$scrl."_Go";
  $apc_state['p'] = $$pagevar;
}
if ( ($apc_state["p"] <= 0) OR ($apc_state["p"]=='-') )
  $apc_state["p"] = 1;

// Set up some variables in apc_state
$apc_state['state'] =  ModW_arr2str($apc_varnames,$apc_state);
$relargs = "apc=" . $apc_state['state'] . ($nocache ? "&nocache=1" : "") . ($debug ? "&debug=1" : "");
$apc_state['relargs'] = $relargs;
// site_url is used as a return address, for example after going into edit
//$apc_state["site_url"] = AA_INSTAL_EDIT_PATH . "modules/site/site.php3?site_id=$site_id&" . $relargs;
$apc_state["site_url"] = "index.shtml?".$relargs;
// Read item from database
$apc_state["item"] = ModW_id2item($apc_state["i"],true);

if ($debug) huhl("<pre>New State=",$apc_state);

// list of all slices used in site. Site module uses cache. If you change the slice
// (you add item, ...), the cache should be cleared for this slice, in order site
// show the newest slice content. In $slice4cache array you will tell the slice
// module, which slices it should take care about
$slices4cache = array(
        "c85a4a34ccbe0ce54c4306b5c32373b1", //BayFM Home
        "498aab17441f29623f404512198566ea",  //BayFM Site
        "8b6a44fb578aa6ee5ffbd7851da5317d", //BayFM discounters
        "0f94e5c07c9593b7199ca076ec079977", //BayFM CSA
        "a5815ddb8287399e1bd6fbb25a3f3e3c", //BayFM pages
        "54315b8263bfd7664b06e920f9a34385", //BayFM presenters
        "29b3878c68a37ae6f495636a7968e48f", //BayFM photos
        "973f382e3ce6f17e2d96318398e6c0dc", //BayFM photo sections
        "6d5bedb31f4db3f5805afb11d8425b54"  //BayFM section
);

// You can define macros here, these can include any of the { ...} syntaxes
$als['editme'] = '<a href="_#EDITITEM"><img src="' . AA_INSTAL_PATH . 'images/edit.gif"></a>'; //Use {editme}
$als['additem'] = '<a href="{alias::f_e:add}"><img src="' . AA_INSTAL_PATH . 'images/add.gif"></a>'; //Use {additem}

// And functions

function myfillform($txt) {
    global $result;
    if ($GLOBALS["post2shtml_id"]) {
       add_post2shtml_vars(true);
        $g="";
        if ($result['success']) return $g;
        while (list($k,$v) = each($result[validate])) {
            $g .= "<br>".$v;  // $v should also indicate name of $k
        }
        //$g .= "<br>post2shtml_id=".$GLOBALS["post2shtml_id"]."<br>"
        //    .serialize($GLOBALS[result]));
        return($g);
    } else {
        return "";
    }
}
$GLOBALS['eb_functions']['myfillform'] = "myfillform";

if ($_SERVER['REMOTE_ADDR'] == "203.43.239.48") {
    print("HI MITRA");
    $errcheck = 1;
//    $debugcache = 1;
    //$debugupload = 1;
    $nocache=1;
} else {
//    $nocache = 1; // Force nocache until caching problems fixed
}

?>
