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

function get_howoften_options () {
    return array (
    "daily"=>_m("daily"),
    "weekly"=>_m("weekly"),
    "monthly"=>_m("monthly"));
}

// ----------------------------------------------------------------------------------------

/* Sessions are used in Alerts to allow that users don't have to write 
   password every time they change something in their settings.
   Each user has his/her session ID and time in the database, the time is 
   updated on every page submit and expires after some time (see below). */

function AlertsUser ($session) {
    global $db;
    $db->query("SELECT * FROM alerts_user WHERE session='$session'");
    // expires after 600 seconds
    if ($db->next_record()) {
        $GLOBALS["email"] = $db->f("email");
        $GLOBALS["lang"] = $db->f("lang");
        bind_mgettext_domain ($GLOBALS["AA_INC_PATH"]."lang/".$GLOBALS["lang"]."_alerts_lang.php3");
        if ($db->f("sessiontime") > time() - 600) {
            $db->query("UPDATE alerts_user SET sessiontime=".time());
            return $db->Record;
        }
        else return 0;
    }
    else return 0;
}

// ----------------------------------------------------------------------------------------

function email_address ($name, $email) {
    if ($name) return "$name <$email>";
    else return $email;
}

// ----------------------------------------------------------------------------------------

/*  Function: alerts_subscribe
    Purpose:  Adds new user to alerts and sends him e-mail to confirm. 
*/
function alerts_subscribe ($email, $lang, $password="", $firstname="", $lastname="")
{
    global $Err, $ALERTS_SUBSCRIPTION_COLLECTION;

    if (!$email) return;

    $db = new DB_AA;
    $varset = new CVarset();

    $db->query("SELECT confirm FROM alerts_user WHERE email='$email'");
    $db->next_record();
    if (strlen ($db->f("confirm")) == 4)
        $confirm = $db->f("confirm");
    else {
        do {        
            $confirm = gensalt(4);
            $db->query("SELECT * FROM alerts_user WHERE confirm='$confirm'");
        } while ($db->num_rows());
        $varset->add("confirm", "text", $confirm);
    }

    $db->query("SELECT * FROM alerts_user WHERE email='$email'");
    if ($db->num_rows() == 0) {   
        $varset->add("email", "quoted", $email);
        $varset->add("password", "quoted", $password ? md5 ($password) : "");
        $varset->add("firstname", "quoted", $firstname);
        $varset->add("lastname", "quoted", $lastname);
        $varset->add("lang", "text", $lang);
        $varset->add("sessiontime", "number", time());
        
        $SQL = "INSERT INTO alerts_user ".$varset->makeINSERT();
        if (!$db->query($SQL)) return _m("ERROR adding user to alerts_user");
    }    
    else {
        $varset->add("password","text","");
        $SQL = "UPDATE alerts_user SET ".$varset->makeUPDATE()." WHERE email='$email'";
        if (!$db->query($SQL)) return _m("ERROR updating user in alerts_user");
    }        

    $subject = _m("Welcome to APC e-mail alerts");   
    $url = AA_INSTAL_URL."ac.php3?id=$confirm&l=$lang";
    $message = _m("<p>Hello,</p>"
        ."<p>please confirm your subscription by clicking on URL:</p>"
        ."%1"
        ."<p>or copy the URL to your web browser so that we can see you did not subscribe by mistake and your e-mail is working.</p>"
        ."<p>Yours<br>"
        ."&nbsp; &nbsp; &nbsp;APC Alerts moderators</p>"
        , array ("<p align=center><a href='$url'>$url</a></p>"));
        
    $db->query("select * from alerts_collection where description='$ALERTS_SUBSCRIPTION_COLLECTION'");
    $db->next_record();   
    $headers = alerts_email_headers ($db->Record, "");
    $to  = email_address ($firstname." ".$lastname, $email);

    global $LANGUAGE_CHARSETS;
    mail_html_text ($to, $subject, $message, $headers, $LANGUAGE_CHARSETS[get_mgettext_lang()], 0); 
    return "";
}

// -----------------------------------------------------------------------------------
//                          COLLECTIONS EDITING

// HTML for Add filter. You must call print_js_options_filters() before using this function.

$ac_filler = "<td class=tabtxt>&nbsp;</td>";
$ac_trstart = "<tr>$ac_filler";
$ac_trend = "$ac_filler</tr>";
    
function print_addfilter ($label, $collection, $index, $class="tabtxt")
{
    global $ac_trstart, $ac_trend; 
    if ($class != -1) echo $ac_trstart.'<td class='.$class.' colspan=2>';
    echo '<b>'.$label.':&nbsp;</b>
    <select name=addfilter['.$collection.']['.$index.']>
    <SCRIPT language=javascript>
    <!--
        document.writeln (options_filters);
    //-->
    </SCRIPT>
    </select>';
    if ($class != -1) echo '&nbsp;</td>'.$ac_trend;
}

// -----------------------------------------------------------------------------------
// prints Add new collection controls
  
function print_add_collection ($user=false)
{
    global $ac_trstart, $ac_trend;
    
    echo $ac_trstart.'<td class=tabtxt colspan=2>'.'<b>'._m("Create new collection").':&nbsp;</b>
      <input type="text" name="cdesc[new]" value="'._m("New collection").'">&nbsp;&nbsp;';

    $button_add = '<input type="submit" name="change[-1]" value="'._m("Create").'">';
    if ($user) {
        FrmSelectEasy("howoften[c][new]", get_howoften_options());
        echo "&nbsp;&nbsp;";
    }
    else echo $button_add;    
    echo '</td>'.$ac_trend;
    for ($i = 0; $i < 3; $i ++) 
        print_addfilter (_m("Filter")." ".($i+1),-1, $i);
    if ($user) 
        echo $ac_trstart."<td colspan=2>".$button_add."</td>".$ac_trend;
}

// ----------------------------------------------------------------------------------- 
// prints javascript defining value options_filters with <OPTION>s for filter select box
  
function print_js_options_filters ($user=true)
{
    $db2 = new DB_AA;
    $db2->query("SELECT slice.name, DF.description as fdesc, DF.id AS filterid FROM
    slice INNER JOIN
    view ON slice.id = view.slice_id INNER JOIN
    alerts_filter DF ON DF.vid = view.id
    ORDER BY slice.name, DF.description");
    
    $jsfilters = "<SCRIPT LANGUAGE=javascript>
    <!--
        var options_filters = '<OPTION value=\"-1\">"._m("Choose a filter")."'";
        while ($db2->next_record()) {
            $jsfilters .= "+'<OPTION value=\"".$db2->f("filterid")."\">";
            //if (!$user) $jsfilters .= $db2->f("filterid").". ";
            $jsfilters .= $db2->f("name")." -- ".$db2->f("fdesc")."'\n";
        }
        $jsfilters .= "
    //-->
    </SCRIPT>";
    echo $jsfilters;
}
  
// -----------------------------------------------------------------------------------
  
function print_edit_collections ($SQL, $script, $user=false)
{
    global $ac_trstart, $ac_trend, $sess;
    $howoften_options = get_howoften_options();

    $db = new DB_AA;
    $db->query($SQL);
    if (!$db->num_rows()) return;
    
    while ($db->next_record()) {
        $allfilters [$db->f("cid")]["cdesc"] = $db->f("cdesc");
        $allfilters [$db->f("cid")]["ho"] = $db->f("howoften");
        $allfilters [$db->f("cid")]["filters"][$db->f("fid")] = 
            array ("name"=>$db->f("name"), "fdesc"=>$db->f("fdesc"));
    }
    
    echo $ac_trstart.'<td class=tabtxt colspan=2><b>'._m("Remove filters by setting order to 0.").'</b></td>'.$ac_trend;

    reset ($allfilters);
    while (list ($cid, $collection) = each ($allfilters)) {
        $button_update = '<input type="submit" name="change['.$cid.']" value="'._m("Update").'">';
        $button_delete = '<input type="submit" name="delete['.$cid.']" value="'._m("Delete").'">';

        echo $ac_trstart.'<td colspan=2><hr></td>'.$ac_trend;
        echo $ac_trstart.'<td class=tabtxt colspan=2><b>'
            ._m("Collection").': </b><input type="text" name="cdesc['.$cid.']" value="'.$collection["cdesc"].'" size="30">&nbsp;&nbsp;';
        if ($user) {
            echo '<b>'._m("How often").':</b> ';
            $howoften_options[-1] = _m("unsubscribe");
            FrmSelectEasy("howoften[c][$cid]", $howoften_options, $collection["ho"]);
        }
        else echo "<b>ID: $cid</b>&nbsp;&nbsp;".$button_update.'&nbsp;&nbsp;'.$button_delete;
        echo '</td>'.$ac_trend;

        reset ($collection["filters"]);
        $irow = 1;
        while (list ($fid, $filter) = each ($collection["filters"])) {
            echo $ac_trstart.'<td class=tabtxt>'._m("Filter").':&nbsp;&nbsp;<b>';
            //if (!$user) echo "$fid. ";
            echo $filter["name"].' - '.$filter["fdesc"].'</b></td>'
                .'<td class=tabtxt>'._m("Order").':&nbsp;'
                .'<input type="text" name="order['.$cid.']['.$fid.']" value="'.$irow.'" size="1"></td>'.$ac_trend;
            $irow ++;
        }

        print_addfilter (_m("Add filter"), $cid, 0);
        
        if ($user) {
            echo $ac_trstart."<td colspan=2>";
            echo $button_update."&nbsp;&nbsp;";
            echo "</td>".$ac_trend;
        }
    }

    echo $ac_trstart.'<td colspan=2><hr></td>'.$ac_trend;
}

// -----------------------------------------------------------------------------------

function update_collection ($db, $cid, $cdesc, $order, $addfilter, $howoften="")
{   
    global $user;
    $howoften_options = get_howoften_options();

    if ($cdesc)
        $db->tquery ("UPDATE alerts_collection SET description='$cdesc' WHERE id=$cid");
        
    if ($howoften) {
        $where = "WHERE userid = $user[id] AND collectionid = $cid";
        if ($howoften_options [$howoften]) 
            $db->tquery ("UPDATE alerts_user_filter SET howoften='$howoften' $where");
        else {
            $db->tquery ("DELETE FROM alerts_user_filter $where");
            $db->tquery ("DELETE FROM alerts_collection_filter WHERE collectionid=$cid");
            $db->tquery ("DELETE FROM alerts_collection WHERE id = $cid");
        }
    }

    $index = 1;

    // update filter order
    if (is_array ($order)) {   
        asort ($order);
        reset ($order);
        while (list ($fid, $ind) = each ($order)) {
            if ($ind == 0) {
                $db->tquery ("DELETE FROM alerts_collection_filter
                    WHERE collectionid = $cid AND filterid = $fid");
            }
            else {
                $db->tquery ("UPDATE alerts_collection_filter SET myindex=$index
                    WHERE collectionid = $cid AND filterid = $fid");
                $index ++;
            }
        }
        // all filters are deleted => delete collection
        if ($index == 1)
            $db->tquery ("DELETE FROM alerts_collection WHERE id = $cid");
    }
    
    // add new filters
    if (is_array ($addfilter)) {
        // leave out duplicities
        asort ($addfilter);
        $prev = -1;
        reset ($addfilter);
        while (list ($newid,$fid) = each ($addfilter)) {
            if ($fid == $prev) unset ($addfilter[$newid]);
            $prev = $fid;
        }
        
        // add filters
        ksort ($addfilter);
        reset ($addfilter);
        while (list (,$fid) = each ($addfilter)) {
            if ($fid != -1 && !isset ($order[$fid])) {
                $db->tquery ("INSERT INTO alerts_collection_filter (collectionid, filterid, myindex)
                    VALUES ($cid, $fid, $index)");
                $index ++;
            }
        }
    }
}

// -----------------------------------------------------------------------------------

// $add_showme sets showme for new collections (1 for admin interface, 0 for public interface)    
function execute_edit_collections ($add_showme, $user=false)
{
    global $debug, $change, $addorder, $addfilter, $cdesc, $delete, $order, $howoften;   
    $db = new DB_AA; 

    if ($debug) {
        print_r ($change); echo "<br>";
        print_r ($addorder); echo "<br>";
        print_r ($addfilter); echo "<br>";
        echo "cdesc[-1] ".$cdesc["new"];
    }
    
    $varset = new CVarset();

    if (is_array ($change)) {
        reset ($change);
        while (list ($cid) = each ($change)) {        
            // add new collection
            if ($cid == -1) {
                $varset->add ("description","quoted",$cdesc["new"]);
                $varset->add ("showme", "number", $add_showme);
                $db->tquery ("INSERT INTO alerts_collection ".$varset->makeINSERT());
                
                $newcid = get_last_insert_id ($db, "alerts_collection");
                if ($howoften["c"]["new"]) 
                    $db->tquery ("INSERT INTO alerts_user_filter
                         (userid,collectionid,howoften)
                         VALUES (".$user["id"].", $newcid, '".$howoften["c"]["new"]."');");
            }
            else $newcid = $cid;
            
            if ($user) $newcid = copy_user_collection ($user["id"], $newcid);
            update_collection ($db, $newcid, $cdesc[$cid], $order[$cid], $addfilter[$cid], $howoften["c"][$cid]);
        }
    }
    
    else if (is_array ($delete)) {
        reset ($delete);
        $cid = key ($delete);
        $db->tquery ("DELETE FROM alerts_collection_filter WHERE collectionid=$cid");
        $db->tquery ("DELETE FROM alerts_collection WHERE id = $cid");
    }
}    

// -----------------------------------------------------------------------------------

// copies collection info and assigns the new cid to user, but only if showme is true

function copy_user_collection ($userid, $cid)
{
    global $db;
    $varset = new CVarset();
    $db->query("SELECT * FROM alerts_collection WHERE id=".$cid." AND showme=1");
    if ($db->num_rows() == 1) {
        $db->next_record();
        $varset->set("description",$db->f("description"),"text");        
        $varset->set("mail_from", $db->f("mail_from"),"text");
        $varset->set("mail_reply_to", $db->f("mail_reply_to"),"text");
        $varset->set("mail_errors_to", $db->f("mail_errors_to"),"text");
        $varset->set("mail_sender", $db->f("mail_sender"),"text");
        $varset->set("showme", 0, "number");
        $db->tquery ("INSERT INTO alerts_collection ".$varset->makeINSERT());
        $newcid = get_last_insert_id ($db, "alerts_collection");
       
        CopyTableRows ("alerts_collection_filter", "collectionid = $cid", 
            array("collectionid"=>$newcid), array ("id"));
            
        $db->tquery("UPDATE alerts_user_filter SET collectionid=$newcid 
            WHERE userid=$userid AND collectionid = $cid");
        return $newcid;
    }
    else return $cid;
}

// -----------------------------------------------------------------------------------

function alerts_email_headers ($record, $default)
{
    $headers = array (
        "From" => "from",
        "Reply-To" => "reply_to",
        "Errors-To" => "errors_to",
        "Sender" => "sender");
    reset ($headers);
    while (list ($header, $field) = each ($headers)) {
        if ($record["mail_$field"])
            $retval .= $header.": ".$record["mail_$field"]."\r\n";
        else if ($default["mail_$field"])
            $retval .= $header.": ".$default["mail_$field"]."\r\n";
    }
    return $retval;
}

// -----------------------------------------------------------------------------------

function AlertsPageBegin() {
    // style sheet
    global $ss, $AA_INSTAL_PATH;
    $stylesheet = $ss ? $ss : $AA_INSTAL_PATH.ADMIN_CSS;

    echo 
    '<!DOCTYPE html public "-//W3C//DTD HTML 4.0 Transitional//EN">
       <HTML>
         <HEAD>
           <LINK rel=StyleSheet href="'.$stylesheet.'" type="text/css"  title="CPAdminCSS">
           <meta http-equiv="Content-Type" content="text/html; charset='.$LANGUAGE_CHARSETS[get_mgettext_lang()].'">';
}           

?>