<?php
/*
 *  Uses a combination of JavaScript and PHP to fill values in anonymous posting
 *  forms and searchforms.
 *
 *  See documentation in doc/anonym.html.
 *
 *	@param bool fillConds If true, the script generates the fillConds code.
 *        Otherwise, the fillForm code is generated.
 *	@param string my_item_id Unpacked long ID of the item.
 *	@param bool notrun If true, doesn't call the JavaScript function
 *        This is useful if you include fillform before the actual HTML form.
 *        You must call fillForm() or fillConds() explicitly by JavaScript
 *        later in code after the form is created.
 *	@param string form The name of the form, if it is not "f".
 *  @param array oldcontent4id Sent by filler to allow to show the values
 *        entered even if some of them was wrong and thus they are not
 *        stored in database. If fillform finds this array, it doesn't try
 *        to load the values by GetItemContent().
 *  @param string show_result Name of a PHP script which receives the array
 *        result created by filler. The script than usually shows some info
 *        about the errors occured to the user.
 *  @param array result Array with error messages passed from filler.php3.
 *
 *  @param string ac  Access Code to be used with Reader Management to
 *        find the correct reader data.
 *  @param string aw  The same Access Code as "ac", but additionally
 *        confirms the reader's email. See doc/reader.html for more info.
 *  @param string au  The same Access Code as "ac", but additionally
 *        unsubscribes the user from the collection, the ID of which is given in $c
 *  @param string c   Collection ID, used in connection with $au (see above).
 *
 *  WARNING: There are some troubles with check boxes. You should never use
 *      checkboxes checked by default (<INPUT TYPE="CHECKBOX" CHECKED>)
 *      in combination with fillform.
 *
 *	 This script contains two similar functions:
 *
 *	 <b>fillForm</b> is used on anonymous posting forms<br>
 *	 <b>fillConds</b> is used on search forms
 *
 *	 <h1>Function fillForm</h1>
 *
 *   Prints a JavaScript function which refills form fields with values from database.
 *
 *   You must supply the var $my_item_id with the item id.
 *
 *   Works well with HTML - Plain text radio buttons and with Dates
 *   represented by 3 select boxes.
 *
 *   <h1>Function fillConds</h1>
 *
 *   Prints a JavaScript function which refills form fields with name conds[][] with
 *	 previous values.
 *
 *   Uses the array conds and function setControlOrAADate - see fillformutils to get a feel
 *   about which form control types are supported (most of them are).
 *
 *   @param array dateConds Special feature: contains names of textfields which are
 *      dates represented by 3 select boxes in the way of AA. E.g. <br>
 *      <tt>dateConds[3]="mydate"</tt> means:<br>
 *      <tt>conds[3][value]</tt> is a date in some strtotime format,
 *      mydate_day is the select box cotaining day, mydate_month contains month,
 *      mydate_year contains year
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
require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/reader_field_ids.php3";
require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/util.php3";

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
echo "<SCRIPT language=javascript src='".AA_INSTAL_URL."javascript/fillform.js'></SCRIPT>\n";

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

if (isset($fillConds))
	fillConds();
else fillForm ();

if ($show_result) {
    if ($result)
        readfile (con_url ($show_result, "result=".urlencode(serialize($result))));
    else readfile ($show_result);

} else if (is_array ($result)) {
    echo "<b>";
    reset ($result);
    while (list ($k,$v) = each ($result)) {
        echo $k.": ";
        if (is_array ($v)) {
            reset ($v);
            while (list ($kk,$vv) = each ($v))
                echo "$kk - $vv ";
        }
        else echo $v;
        echo "<br>\n";
    }
    echo "</b>";
}

// ----------------------------------------------------------------------------
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

// ----------------------------------------------------------------------------
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

/** Finds the item content and calls fillFormWithContent.
*   Prooves permissions to update an item.
*/
function fillForm () {
    global $my_item_id, $slice_id, $oldcontent4id, $db;

    if (! $slice_id) { echo "fillform.php3: error: Slice ID not set."; return; }
    $slice_info = GetSliceInfo($slice_id);

    // reader management: aw=ABCDE is sent in welcome
    // emails to confirm the email address
    if ($slice_info["type"] == "ReaderManagement") {
        if ($GLOBALS["aw"]) {
            $GLOBALS["ac"] = $GLOBALS["aw"];
            if (confirm_email ())
                $GLOBALS["result"]["email_confirmed"] = "OK";
        }
        if ($GLOBALS["au"]) {
            $GLOBALS["ac"] = $GLOBALS["au"];
            if (unsubscribe_reader ())
                $GLOBALS["result"]["unsubscribed"] = "OK";
        }
    }

    if (is_array ($oldcontent4id)) {
        fillFormWithContent ($oldcontent4id);
        return;
    }

    // For Reader management slices we use special ways to find the item:
    // either HTTP auth info, or the access code. No other possibility.

    if ($slice_info["type"] == "ReaderManagement") {
        if ($slice_info["permit_anonymous_edit"] == ANONYMOUS_EDIT_HTTP_AUTH) {
            if (! $_SERVER["REMOTE_USER"])
                ; // if no user is sent, this is perhaps the subscribe page
                  // which is out of the protected folder
            else {
                $db->tquery (
                  "SELECT item.id FROM content INNER JOIN item
                   ON content.item_id = item.id
                   WHERE item.slice_id='".q_pack_id($slice_id)."'
                   AND content.field_id='".FIELDID_USERNAME."'
                   AND content.text='".addslashes($_SERVER["REMOTE_USER"])."'");
                if ($db->num_rows() != 1)
                { echo "<!--HTTP AUTH USER not OK-->"; return; }
                $db->next_record();
                $my_item_id = unpack_id ($db->f("id"));
            }
        }
        // access code
        else if ($GLOBALS["ac"]) {
            $db->tquery (
                "SELECT item.id FROM content INNER JOIN item
                 ON content.item_id = item.id
                 WHERE item.slice_id='".q_pack_id($slice_id)."'
                 AND content.field_id='".FIELDID_ACCESS_CODE."'
                 AND content.text='".$GLOBALS["ac"]."'");
            if ($db->num_rows() != 1)
            { echo "<!--ACCESS CODE not OK-->"; return; }
            $db->next_record();
            $my_item_id = unpack_id ($db->f("id"));
        }
    }

    $oldcontent = GetItemContent($my_item_id);
    $oldcontent4id = $oldcontent[$my_item_id];
    if (!is_array ($oldcontent4id))
    { echo "<!--fillform: no item found-->"; return; }

    $permsok = true;
    // Do not show items which are not allowed to be updated
    switch ($slice_info["permit_anonymous_edit"]) {
    case ANONYMOUS_EDIT_NOT_ALLOWED: $permsok = false; break;
    case ANONYMOUS_EDIT_ONLY_ANONYMOUS:
    case ANONYMOUS_EDIT_NOT_EDITED_IN_AA:
    	$permsok = ($oldcontent4id["flags..........."][0]['value']
           & ITEM_FLAG_ANONYMOUS_EDITABLE != 0);
        break;
    }
    if (!$permsok) { echo "<!--this item is not allowed to be updated-->"; return; }

    fillFormWithContent ($oldcontent4id);
}

/* Jakub: I had troubles with the packed IDs because some chars (codes > 128)
    from them appear as
	single quote - of course it depends on used char-encoding and therefore
	is hard to solve. I have forbidden id and slice_id to appear and hope this
	is enough. */

function fillFormWithContent ($oldcontent4id) {
	global $form, $suffix, $conds, $dateConds, $my_item_id, $checkbox;

    $timezone = getTimeZone();

    echo $GLOBALS["jsstart"];
	echo "
    var fillform_fields".$suffix." = new Array (\n";

    $first = true;
	if (is_array ($oldcontent4id)) {
		reset ($oldcontent4id);
		while (list ($field_id,$field_array) = each ($oldcontent4id)) {
            if (is_array ($field_array))
			foreach ($field_array as $field) {
				$myvalue = safeChars ($field["value"]);
				//$control_id = $field_id;
				$control_id = 'v'.unpack_id128($field_id);
				// field password.......x is used to authenticate item edit
				if (substr ($field_id, 0, 14) != "password......"
					&& $field_id != "id.............."
					&& $field_id != "slice_id........"
					//&& $myvalue != ""
                    ) {
                    if (!$first) echo ",\n"; else $first = false;
					echo "\t\tnew Array ('$form','$control_id','$myvalue','tdctr_','".
					($field["flag"] & FLAG_HTML ? "h" : "t")."',$timezone)";
                }
			}
		}
	}

    echo ");

    function fillForm".$suffix."() {
        setControl ('$form','my_item_id','$my_item_id');
        for (i=0; i < fillform_fields".$suffix.".length; ++i) {
            var item = fillform_fields".$suffix."[i];
            setControlOrAADate (item[0],item[1],item[2],item[3],item[4],item[5]);
        }
    }\n";
	if (!isset ($GLOBALS["notrun"])) echo "
    fillForm ();";
    echo $GLOBALS["jsfinish"];
}

/** Confirms email on Reader management slices because the parameter $aw
*   is sent only in Welcome messages.
*   Returns true if email exists and not yet confirmed, false otherwise.
*/
function confirm_email() {
    global $slice_id;

    require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";

    $db->query (
        "SELECT item.id FROM content INNER JOIN item
         ON content.item_id = item.id
         WHERE item.slice_id='".q_pack_id($slice_id)."'
         AND content.field_id='".FIELDID_ACCESS_CODE."'
         AND content.text='".$GLOBALS["aw"]."'");
    if ($db->num_rows() != 1)
    { echo "<!--AW not OK: ".$db->num_rows()." items-->"; return; }
    $db->next_record();
    $item_id = unpack_id ($db->f("id"));

    $db->query (
        "SELECT text FROM content
        WHERE field_id = '".FIELDID_MAIL_CONFIRMED."'
        AND item_id = '".q_pack_id($item_id)."'");

    if ($db->next_record()) {
        if ($db->f("text") != "" && ! $db->f("text")) {
            $db->query (
                "UPDATE content SET text='1'
                WHERE field_id = '".FIELDID_MAIL_CONFIRMED."'
                AND item_id = '".q_pack_id($item_id)."'");
            echo "<!--OK: email confirmed-->";
            return true;
        }
    }

    return false;
}

function unsubscribe_reader() {
    global $slice_id;

    require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";

    $db->query (
        "SELECT item.id FROM content INNER JOIN item
         ON content.item_id = item.id
         WHERE item.slice_id='".q_pack_id($slice_id)."'
         AND content.field_id='".FIELDID_ACCESS_CODE."'
         AND content.text='".$GLOBALS["au"]."'");
    if ($db->num_rows() != 1)
    { echo "<!--AU not OK: ".$db->num_rows()." items-->"; return; }
    $db->next_record();
    $item_id = unpack_id ($db->f("id"));

    $field_id = getAlertsField (FIELDID_HOWOFTEN, $GLOBALS["c"]);
    $db->query (
        "SELECT text FROM content
        WHERE field_id = '".$field_id."'
        AND item_id = '".q_pack_id($item_id)."'");

    if ($db->next_record()) {
        if ($db->f("text")) {
            $db->query (
                "UPDATE content SET text=''
                WHERE field_id = '".$field_id."'
                AND item_id = '".q_pack_id($item_id)."'");
            echo "<!--OK: f $field_id unsubscribed-->";
            return true;
        }
    }

    return false;
}

?>
