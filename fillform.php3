<?php 
/* 
 *  Uses a combination of JavaScript and PHP to fill values in anonymous posting
 *  forms and searchforms.	
 *	
 *	Params:
 *		my_item_id .. unpacked long ID of the item
 *		fillConds=1 .. generates the fillConds code (otherwise, generate fillForm code)
 *		notrun=1 .. doesn't run the JavaScript function
 *		form=formname .. look for the controls in the form formname. If not set, will use 'f'.
 *      called_from_filler=1 .. do not even show JavaScript, because filler sends
 *                              the item content to be re-displayed
 *      lookup_conds[] .. if you want to find item not by $my_item_id
 *             but by specified conditions. You must ensure the condition gives
 *             exactly 1 result.
 *      oldcontent4id
 *      show_result
 *      use_http_auth
 *	
 *	This script contains two similar functions:
 *	
 *	fillForm is used on anonymous posting forms
 *	fillConds is used on search forms 
 *
 *	<h1>Function fillForm</h1>
 *	
 *   Prints a JavaScript function which refills form fields with values from database
 *   
 *   you must supply the var $my_item_id with the item id, and that item must have
 *   ITEM_FLAG_ANONYMOUS_EDITABLE set
 *   
 *   Works well with HTML - Plain text radio buttons and with Dates represented by 3 select boxes
 *   
 *   <h1>Function fillConds</h1>
 *
 *   Prints a JavaScript function which refills form fields with name conds[][] with 
 *	 previous values
 *
 *   uses the array conds and function setControlOrAADate - see fillformutils to get a feel
 *   about which form control types are supported (most of them are)
 *
 *   special feature: array dateConds may contain names of textfields which are 
 *   dates represented by 3 select boxes in the way of AA. E.g. by fillConds:
 *
 *   dateConds[3]="mydate" means:
 *   
 *   conds[3][value] is a date in some strtotime format, mydate_day is the select
 *   box cotaining day, mydate_month contains month, mydate_year contains year 
 *   
 * @package UserInput
 * @version $Id$
 * @author Jakub Adamek <jakubadamek@ecn.cz> 
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications 
*/ 
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

if (!isset ($form)) $form = "f";

$encap = ( ($encap=="false") ? false : true );

/** APC-AA configuration file */
require_once "include/config.php3";
/** Defines simplified class for page scroller */
require_once $GLOBALS["AA_INC_PATH"]."easy_scroller.php3";
/** Set of useful functions used on most pages */
require_once $GLOBALS["AA_INC_PATH"]."util.php3";
/**  Defines class for item manipulation (shows item in compact or fulltext format, replaces aliases ...) */
require_once $GLOBALS["AA_INC_PATH"]."item.php3";
/** parses view settings, gets view data and other functions */
require_once $GLOBALS["AA_INC_PATH"]."view.php3";
/** defines PageCache class used for caching informations into database */
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
/** functions for searching and filtering items */
require_once $GLOBALS["AA_INC_PATH"]."searchlib.php3";
/** discussion utility functions */
require_once $GLOBALS["AA_INC_PATH"]."discussion.php3";
/** Defines class for inserting and updating database fields */
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
/** Main include file for using session management function on an encapsulated (.shtml) page */
if ($encap) require_once $GLOBALS["AA_INC_PATH"]."locsessi.php3";
/** Main include file for using session management function on a page */
else require_once $GLOBALS["AA_INC_PATH"]."locsess.php3"; 

page_open(array("sess" => "AA_SL_Session"));

function RestoreVariables() {
  $r_state_vars = unserialize($GLOBALS[r_packed_state_vars]);
  if( isset($r_state_vars) AND is_array($r_state_vars) ) {
    reset($r_state_vars);
    while( list($k,$v) = each( $r_state_vars ) )
      $GLOBALS[$k] = $v;
  }
}  
RestoreVariables();

// core JavaScript functions
echo "<SCRIPT language=javascript src='".$AA_INSTAL_PATH."javascript/fillform.js'></SCRIPT>";

# init used objects
$db = new DB_AA;
$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();
$itemvarset = new Cvarset();

add_vars();
add_post2shtml_vars();

$jsstart = "<SCRIPT language=JavaScript>
<!--\n";
$jsfinish = "
// -->
</SCRIPT>\n";

/* * * * * * * * * * * FILL CONDS * * * * * * * * * */
/** gives JavaScript filling the AA date 3 selectboxes
	params: $mydate .. UNIX timestamp
			$dateField .. field name
*/
function fillConds () {
	global $form, $conds, $dateConds;
    global $conds_not_field_names;  
	
	echo $GLOBALS["jsstart"]
        ."function fillConds () {";
	   
	if (is_array ($conds)) {
		reset ($conds);
		while (list ($i,$cond) = each ($conds)) {
        	if (is_array($cond)) {
        		reset ($cond);
                while( list($k, $v) = each($cond) ) {
                    if( $v ) {
                        if (!is_array ($v)) {
                            $v = str_replace ("\"", '\\"', $v);
                    		echo "setControl ('$form','conds[$i][$k]',\"$v\");\n";
                        }
                        else {
                            $arr = "";
                            reset ($v);
                            while (list (,$vv) = each ($v)) {
                                if ($arr != "") $arr .= ","; 
                                $arr .= "'".str_replace ("'", "\\'", $vv) . "'";
                            }
                            echo "setControlArray ('$form','conds[$i][$k][]',new Array($arr));\n";
                        }
                    }
                }
            }    
		}
	}
	
	if (is_array ($dateConds)) {
        $timezone = getTimeZone();
		reset ($dateConds);
		while (list($i,$dateField) = each ($dateConds)) {
			if (isset ($conds[$i][value]) && $conds[$i][value]) 
				echo "setControlOrAADate ('$form','$dateField','".
					strtotime($conds[$i][value])."','',0,$timezone);\n";
		}
	}
	echo "\n}\n";
	
	if (!isset ($GLOBALS["notrun"])) echo "\n fillConds ();\n";
    echo $GLOBALS["jsfinish"];
}

/* * * * * * * * * * * FILL FORM * * * * * * * * * */

function safeChars ($str) {
  for ($i=0; $i < strlen ($str); ++$i) {
    if ($str[$i] == "\n") $retVal .= "\\n";
    if (ord($str[$i]) > 31) 
		if ($str[$i] == "'" ) $retVal .= "\\'";
		else $retVal .= $str[$i];
  }
  return $retVal;
}

/* Jakub: I had troubles with the packed IDs because some chars (codes > 128) 
    from them appear as
	single quote - of course it depends on used char-encoding and therefore
	is hard to solve. I have forbidden id and slice_id to appear and hope this
	is enough. */

function fillForm () {
    global $my_item_id, $lookup_conds, $slice_id, $oldcontent4id, $db;
    
    if ($GLOBALS["use_http_auth"]) {
        if (! $slice_id)
            echo "Error: You must send slice ID if you want use_http_auth!";
        $db->query (
            "SELECT item.id FROM content INNER JOIN item 
             ON content.item_id = item.id
             WHERE item.slice_id='".q_pack_id($slice_id)."'
             AND content.field_id='headline........'
             AND content.text='".addslashes($_SERVER["REMOTE_USER"])."'");
        if ($db->num_rows() != 1)
            return;
        $db->next_record();
        $my_item_id = unpack_id128 ($db->f("id"));
    }

    else if (is_array ($lookup_conds)) {
        list ($fields) = GetSliceFields ($slice_id);
        $zids = QueryZIDs($fields, $slice_id, $lookup_conds, "", "", "ALL");
        if ($zids->count() == 1) 
            $my_item_id = $zids->longids(0);
        else return;
    }            
        
    if (!is_array ($oldcontent4id)) {        
        $oldcontent = GetItemContent($my_item_id);
        $oldcontent4id = $oldcontent[$my_item_id];   
    }
    
    fillFormWithContent ($oldcontent4id);
}

function fillFormWithContent ($oldcontent4id) {
	global $form, $conds, $dateConds, $my_item_id;

	if (!is_array ($oldcontent4id)) {
        echo $GLOBALS["jsstart"];
        echo "
        var fillform_fields = new Array ();
        function fillForm() {} \n"; 
        echo $GLOBALS["jsfinish"];
        return;
    }
    
	# are we allowed to update this item?
	if ($oldcontent4id["flags..........."][0]['value'] 
       & ITEM_FLAG_ANONYMOUS_EDITABLE == 0) {
        echo $GLOBALS["jsstart"];    
		echo "
        <!-- This item isn't allowed to be changed anonymously. -->
        var fillform_fields = new Array ();
        function fillForm() {} \n"; 
        echo $GLOBALS["jsfinish"];        
		return;
	}
	
    $timezone = getTimeZone();

    echo $GLOBALS["jsstart"];
	echo "
    var fillform_fields = new Array (\n";
	
    $first = true;
	if (is_array ($oldcontent4id)) {
		reset ($oldcontent4id);
		while (list ($field_id,$field_array) = each ($oldcontent4id)) {
            if (is_array ($field_array)) {
    			reset ($field_array);
    			while (list (,$field) = each ($field_array)) {
    				$myvalue = safeChars ($field[value]);
    				//$control_id = $field_id;
    				$control_id = 'v'.unpack_id128($field_id);
    				// field password.......x is used to authenticate item edit
    				if (substr ($field_id, 0, 14) != "password......" 
    					&& $field_id != "id.............."
    					&& $field_id != "slice_id........"
    					&& $myvalue != "") {
                        if (!$first) echo ",\n"; else $first = false;
    					echo "\t\tnew Array ('$form','$control_id','$myvalue','tdctr_','".
    					($field[flag] & FLAG_HTML ? "h" : "t")."',$timezone)";
                    }
    			}
            }
		}
	}
	
    echo "); 
    
    function fillForm () { 
        setControl ('$form','my_item_id','$my_item_id');
        for (i=0; i < fillform_fields.length; ++i) { 
            var item = fillform_fields[i]; 
            setControlOrAADate (item[0],item[1],item[2],item[3],item[4],item[5]); 
        } 
    }\n";
	if (!isset ($GLOBALS["notrun"])) echo "
    fillForm ();";
    echo $GLOBALS["jsfinish"];    
}

if ($show_result)
    @readfile (con_url ($show_result, "result=".urlencode(serialize($result))));

if (isset($fillConds)) 
	fillConds();
else fillForm ();
?>
