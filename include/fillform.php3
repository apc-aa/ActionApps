<?php
/*
 * Extracted from fillform.php3 to allow inclusion from site module
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
 * @package UserInput
 * @version $Id$
 * @author Mitra based on code from fillform.php3 by Jakub Adamek <jakubadamek@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/

// require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/reader_field_ids.php3";

function fillFormFromVars($fillConds) {
    global $err, $jsstart, $jsfinish;
    $res = "";
    // core JavaScript functions
    $res .= "<SCRIPT language=javascript src='".AA_INSTAL_URL."javascript/fillform.js'></SCRIPT>\n";

    # init used objects
    $err["Init"] = "";          // error array (Init - just for initializing variable
    //Doesn't look like these are used
    //$varset = new Cvarset();
    //$itemvarset = new Cvarset();

    add_vars();
    add_post2shtml_vars();

    $jsstart = "<SCRIPT language=JavaScript>
<!--\n";
    $jsfinish = "
// -->
</SCRIPT>\n";

    if (isset($fillConds))
        $res .= fillConds();
    else $res .= fillForm();
    return $res;
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
*   Returns stirng of HTML and Javascript for echoing
*/
function fillForm () {
    global $my_item_id, $slice_id, $oldcontent4id;
    // get slice_id if not specified - try get it from $my_item_id
    if ( !$slice_id AND $my_item_id ) {
        $zid = new zids( $my_item_id, 'l' );
        $content = GetItemContentMinimal($zid, array('id','slice_id'));
        if ( $p_slice_id = $content[$my_item_id]["slice_id........"][0]['value'] ) {
            $slice_id = unpack_id128($p_slice_id);
        }
    }
    // get slice_id if not specified - try get it from $oldcontent4id
    if ( !$slice_id AND $oldcontent4id AND is_array($oldcontent4id) ) {
        if ( $p_slice_id = $oldcontent4id["slice_id........"][0]['value'] ) {
            $slice_id = unpack_id128($p_slice_id);
        }
    }
    if ( !$slice_id ) {
       echo "<!-- fillform.php3: Slice ID not set, but it could be OK -->";
       return;
    }

    $slice_info = GetSliceInfo($slice_id);

    // reader management: aw=ABCDE is sent in welcome
    // emails to confirm the email address
    if ($slice_info["type"] == "ReaderManagement") {
        if ($GLOBALS["aw"]) {
            $GLOBALS["ac"] = $GLOBALS["aw"];
            if (confirm_email())
                $GLOBALS["result"]["email_confirmed"] = "OK";
        }
        if ($GLOBALS["au"]) {
            $GLOBALS["ac"] = $GLOBALS["au"];
            if (unsubscribe_reader ())
                $GLOBALS["result"]["unsubscribed"] = "OK";
        }
    }
    if (is_array ($oldcontent4id)) {
        return fillFormWithContent ($oldcontent4id);
    }

    // For Reader management slices we use special ways to find the item:
    // either HTTP auth info, or the access code. No other possibility.

    if ($slice_info["type"] == "ReaderManagement") {
        if ($slice_info["permit_anonymous_edit"] == ANONYMOUS_EDIT_HTTP_AUTH) {
            if (! $_SERVER["REMOTE_USER"])
                ; // if no user is sent, this is perhaps the subscribe page
                  // which is out of the protected folder
            else {
                $db = getDB();
                $db->tquery (
                  "SELECT item.id FROM content INNER JOIN item
                   ON content.item_id = item.id
                   WHERE item.slice_id='".q_pack_id($slice_id)."'
                   AND content.field_id='".FIELDID_USERNAME."'
                   AND content.text='".addslashes($_SERVER["REMOTE_USER"])."'");
                if ($db->num_rows() != 1)
                { freeDB($db); return "<!--HTTP AUTH USER not OK-->"; }
                $db->next_record();
                $my_item_id = unpack_id ($db->f("id"));
                freeDB($db);
            }
        }
        // access code
        else if ($GLOBALS["ac"]) {
            $db = getDB();
            $SQL =
                "SELECT item.id FROM content INNER JOIN item
                 ON content.item_id = item.id
                 WHERE item.slice_id='".q_pack_id($slice_id)."'
                 AND content.field_id='".FIELDID_ACCESS_CODE."'
                 AND content.text='".$GLOBALS["ac"]."'";
            $db->tquery ($SQL);
            if ($db->num_rows() != 1)
            {  huhe("Warning, invalid access code '",$GLOBALS["ac"],"'");
                freeDB($db); return "<!--ACCESS CODE not OK-->"; }
            $db->next_record();
            $my_item_id = unpack_id ($db->f("id"));
            freeDB($db);
        }
    }

    $oldcontent = GetItemContent($my_item_id);
    $oldcontent4id = $oldcontent[$my_item_id];
    if (!is_array ($oldcontent4id))
    { return "<!--fillform: no item found-->"; }

    $permsok = true;
    // Do not show items which are not allowed to be updated
    switch ($slice_info["permit_anonymous_edit"]) {
    case ANONYMOUS_EDIT_NOT_ALLOWED: $permsok = false; break;
    case ANONYMOUS_EDIT_ONLY_ANONYMOUS:
    case ANONYMOUS_EDIT_NOT_EDITED_IN_AA:
        $permsok = (($oldcontent4id["flags..........."][0]['value']
           & ITEM_FLAG_ANONYMOUS_EDITABLE) != 0);
        break;
    }
    if (!$permsok) { return "<!--this item is not allowed to be updated-->"; }

    return fillFormWithContent($oldcontent4id);
}

/* Jakub: I had troubles with the packed IDs because some chars (codes > 128)
    from them appear as
    single quote - of course it depends on used char-encoding and therefore
    is hard to solve. I have forbidden id and slice_id to appear and hope this
    is enough. */
/* Returns HTML and Javascript for echoing */
function fillFormWithContent ($oldcontent4id) {
    global $form, $suffix, $conds, $dateConds, $my_item_id, $checkbox;

    $timezone = getTimeZone();
    $res = "";

    $res .= $GLOBALS["jsstart"];
    $res .= "
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
                    if (!$first) $res .= ",\n"; else $first = false;
                    $res .= "\t\tnew Array ('$form','$control_id','$myvalue','tdctr_','".
                    ($field["flag"] & FLAG_HTML ? "h" : "t")."',$timezone)";
                }
            }
        }
    }

    $res .= ");

    function fillForm".$suffix."() {
        setControl ('$form','my_item_id','$my_item_id');
        for (i=0; i < fillform_fields".$suffix.".length; ++i) {
            var item = fillform_fields".$suffix."[i];
            setControlOrAADate (item[0],item[1],item[2],item[3],item[4],item[5]);
        }
    }\n";
    if (!isset ($GLOBALS["notrun"])) $res .= "
    fillForm ();";
    $res .= $GLOBALS["jsfinish"];
    return $res;
}

/** Confirms email on Reader management slices because the parameter $aw
*   is sent only in Welcome messages.
*   Returns true if email exists and not yet confirmed, false otherwise.
*/
function confirm_email() {
    global $slice_id;

    require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";
    $db = getDB();
    $db->query (
        "SELECT item.id FROM content INNER JOIN item
         ON content.item_id = item.id
         WHERE item.slice_id='".q_pack_id($slice_id)."'
         AND content.field_id='".FIELDID_ACCESS_CODE."'
         AND content.text='".$GLOBALS["aw"]."'");
    if ($db->num_rows() != 1) {
        if ($debug) { echo "AW not OK: ".$db->num_rows()." items"; }
        freeDB($db);
        return false;
    }
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
            if ($debug) echo "<!--OK: email confirmed-->";
            freeDB($db);
            return true;
        }
    }
    freeDB($db);
    return false;
}

function unsubscribe_reader() {
    global $slice_id;

    require_once $GLOBALS["AA_INC_PATH"]."itemfunc.php3";
    $db = getDB();
    $db->query (
        "SELECT item.id FROM content INNER JOIN item
         ON content.item_id = item.id
         WHERE item.slice_id='".q_pack_id($slice_id)."'
         AND content.field_id='".FIELDID_ACCESS_CODE."'
         AND content.text='".$GLOBALS["au"]."'");
    if ($db->num_rows() != 1) {
        if ($debug) echo "AU not OK: ".$db->num_rows()." items";
        freeDB($db);
        return false;
    }
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
            if ($debug) echo "<!--OK: f $field_id unsubscribed-->";
            freeDB($db);
            return true;
        }
    }
    freeDB($db);
    return false;
}

// ----------------------------------------------------------------------------
/* * * * * * * * * * * FILL CONDS * * * * * * * * * */
/** gives JavaScript filling the AA date 3 selectboxes
    params: $mydate .. UNIX timestamp
            $dateField .. field name
*/
function fillConds () {
    global $form, $conds, $dateConds;
    $res = "";
    $res .= $GLOBALS["jsstart"]
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
                            $res .= "setControl ('$form','conds[$i][$k]',\"$v\");\n";
                        }
                        else {
                            $arr = "";
                            reset ($v);
                            while (list (,$vv) = each ($v)) {
                                if ($arr != "") $arr .= ",";
                                $arr .= "'".str_replace ("'", "\\'", $vv) . "'";
                            }
                            $res .= "setControlArray ('$form','conds[$i][$k][]',new Array($arr));\n";
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
                $res .= "setControlOrAADate ('$form','$dateField','".
                    strtotime($conds[$i][value])."','',0,$timezone);\n";
        }
    }
    $res .= "\n}\n";

    if (!isset ($GLOBALS["notrun"])) $res .= "\n fillConds ();\n";
    $res .= $GLOBALS["jsfinish"];
    return $res;
}


?>
