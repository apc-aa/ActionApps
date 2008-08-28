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
 *      <tt>conds[3]['value']</tt> is a date in some strtotime format,
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
require_once AA_INC_PATH."easy_scroller.php3";
/** Set of useful functions used on most pages */
require_once AA_INC_PATH."util.php3";
/**  Defines class for item manipulation (shows item in compact or fulltext format, replaces aliases ...) */
require_once AA_INC_PATH."item.php3";
/** parses view settings, gets view data and other functions */
require_once AA_INC_PATH."view.php3";
/** defines PageCache class used for caching informations into database */
require_once AA_INC_PATH."pagecache.php3";
/** functions for searching and filtering items */
require_once AA_INC_PATH."searchlib.php3";
/** discussion utility functions */
require_once AA_INC_PATH."discussion.php3";
/** Defines class for inserting and updating database fields */
require_once AA_INC_PATH."varset.php3";
/** Main include file for using session management function on a page */
require_once AA_INC_PATH."locsess.php3";

require_once AA_BASE_PATH."modules/alerts/util.php3";
/** Used for getFrmJavascript() */
require_once AA_INC_PATH."formutil.php3";
/** Some functions pulled from here to allow inclusion elsewhere */
require_once AA_INC_PATH."fillform.php3";

page_open(array("sess" => "AA_SL_Session"));

function RestoreVariables() {
    $r_state_vars = unserialize($GLOBALS['r_packed_state_vars']);
    if (isset($r_state_vars) AND is_array($r_state_vars)) {
        foreach ( $r_state_vars as $k=>$v ) {
            $GLOBALS[$k] = $v;
        }
    }
}
RestoreVariables();

echo fillFormFromVars($fillConds);

if ($show_result) {
    if ($result) {
        readfile(con_url($show_result, "result=".urlencode(serialize($result))));
    } else {
        readfile($show_result);
    }
} elseif (is_array($result)) {
    echo "<b>";
    foreach ( $result as $k => $v) {
        echo $k.": ";
        if (is_array($v)) {
            foreach ($v as $kk => $vv) {
                echo "$kk - $vv ";
            }
        } else {
            echo $v;
        }
        echo "<br>\n";
    }
    echo "</b>";
}

?>
