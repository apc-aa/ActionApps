<?php
/**
 * Functions for preparing the Filters and Collections output and sending Alerts with new messages.
 * 
 * @package Alerts
 * @version $Id$
 * @author Jakub Adámek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications 
*/
/* 
Copyright (C) 1999-2002 Association for Progressive Communications 
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

//require "lang.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";
require $GLOBALS[AA_INC_PATH]."view.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."searchlib.php3";
require $GLOBALS[AA_INC_PATH]."mail.php3";

//$debug = 1;

$db = new DB_AA;

// -------------------------------------------------------------------------------------
/**  Finds and formats items, writes the result into alerts_filter_howoften.
*    WARNING:  the function does not check when were the items sent last time, call it only once a day / week / month
*
*    @param $ho = how often 
*/
function create_filter_text ($ho)
{
    $db->query("
    SELECT DF.conds, view.slice_id, DF.id AS filterid, DF.vid, slice.name AS slicename, 
        slice.lang_file, FH.last
        FROM alerts_filter DF INNER JOIN
             alerts_filter_howoften FH ON DF.id = FH.filterid INNER JOIN
             view ON view.id = DF.vid INNER JOIN
             slice ON slice.id = view.slice_id
        ORDER BY view.slice_id, DF.vid
        WHERE FH.howoften='$ho'");
    
    while ($db->next_record()) {
        $slices[$db->f("slice_id")]["name"] = $db->f("slicename");
        $slices[$db->f("slice_id")]["lang"] = substr ($db->f("lang_file"),0,2);
        $slices[$db->f("slice_id")]["views"][$db->f("vid")]["filters"][$db->f("filterid")] = 
            array ("conds"=>$db->f("conds"), "last"=>$db->f("last"));
    }
    create_filter_text_from_list ($ho, $slices);
}    

// -------------------------------------------------------------------------------------
/**  Creates Filter output. Finds and formats items, writes the result into alerts_filter_howoften.
*    WARNING:  the function does not check when were the items sent last time, call it only once a day / week / month
*
*    @param $ho         = how often 
*    @param $update     decides whether alerts_filter_howoften.last would be updated, 
*                       i.e. whether this is only a debug trial or the real life
*/
function create_filter_text_from_list ($ho, $slices, $update=true)
{
    global $debug, $db, $conds, $sort;
    $howoften_options = get_howoften_options();
    
    initialize_filters ();
    
    // first I create a hierarchical array $slices and than use it instead of the recordset
            
    $varset = new CVarset();
    $varset->addkey ("howoften", "text", $ho);
            
    reset ($slices);
    while (list ($p_slice_id, $slice) = each ($slices)) {
        $slice_id = unpack_id ($p_slice_id);
        list($fields) = GetSliceFields($slice_id);
        $aliases = GetAliasesFromFields($fields, $als);       
        // set language
        bind_mgettext_domain ($GLOBALS[AA_INC_PATH]."lang/".$slice["lang"]."_alerts_lang.inc");
        
        reset ($slice["views"]);
        while (list ($vid, $view) = each ($slice["views"])) {                      
            $view_info = GetViewInfo($vid);
            $format = GetViewFormat($view_info); 
            
            reset ($view["filters"]);
            while (list ($fid, $filter) = each ($view["filters"])) {
                $now = time() - 1;
                $last = $filter["last"];
                
/* this query is carefully made to include only items, which:                
     a) 1. were moved to active between $last and $now 
        2. and were active (not expired nor pending) between $last and $now,
            i.e. publish_date <= $now AND expiry_date >= $last
     b) 1. were moved to active before $last
        2. and were active (not expired nor pending) between $last and $now
        3. and were not active until $last,
            i.e. publish_date > $last
            
   The field moved2active is filled whenever an item is moved from other bin to Active bin 
       or when it is a new item inserted into Active bin. 
   The field is cleared whenever the item is moved from Active bin to another one.
*/
                
                $db->query(
                    "SELECT id FROM item "
                   ."WHERE publish_date <= $now AND expiry_date >= $last "  # a) 2. and b) 2.
                       ."AND ((moved2active BETWEEN $last AND $now) "       # a) 1.
                             ."OR (moved2active < $last "                   # b) 1.
                                 ."AND publish_date > $last) "              # b) 3.
                           .")");
                           
                $all_ids = "";
                while ($db->next_record())
                    $all_ids[] = $db->f("id");

                $varset->addkey ("filterid", "number", $fid);
                if (!$debug && $update) {
                    $varset->add ("last", "number", $now);
                    $db->query ($varset->makeINSERTorUPDATE("alerts_filter_howoften"));
                    $varset->remove ("last");
                }
                    
                $dbconds = $filter["conds"];
                if ($debug) echo "<br>Slice ".$slice["name"].", conds ".$dbconds."<br>";       
                $conds = ""; $sort = "";
                add_vars ($dbconds);
                if ($debug) { print_r ($conds); echo "<br>"; }
        
                $items_text = "";
                if (is_array ($all_ids)) {
                    // find items for the given filter
                    $item_ids = QueryIDs ($fields, $slice_id, $conds, $sort, "", "ACTIVE", "", 0, $all_ids);
                    if ($debug) echo "<br>Item IDs count: ".count($item_ids)."<br>";
                    if( count($item_ids) > 0 ) { 
                        $itemview = new itemview( $db, $format, $fields, $aliases, $item_ids, 
                              0, $view_info["listlen"], shtml_url());                          
                        $items_text = $itemview->get_output ("view");        
                    }
                }
                
                if ($debug && $items_text) echo "<hr>Items text:<br><br>$items_text<br><br><hr>";
                
                $varset->add ("text", "text", $items_text);
                $db->query ($varset->makeINSERTorUPDATE ("alerts_filter_howoften"));
            }
        }
    }
}

// -------------------------------------------------------------------------------------
/**  Sends emails to all or chosen users, from all or chosen collections.
*
*   @param $ho         = how often 
*   @param $collection_ids = array (collection_id, collection_id, ...).
*                          If not filled, all collections are processed.
*   @param $email      = array (email, email, ...)
*                      If not filled, all Alerts users are processed.
*/    
function send_emails ($ho, $collection_ids = "all", $emails = "all")
{
    global $debug, $db, $conds, $ALERTS_DEFAULT_COLLECTION, $LANGUAGE_CHARSETS;
            
    if (is_array ($collection_ids))
        $where = " WHERE AC.id IN (".join (",", $collection_ids).")";    
    $db->query ("SELECT module.slice_url, AC.id AS collectionid, AC.*, email.* FROM alerts_collection AC
            INNER JOIN email ON AC.emailid_alert = email.id
            INNER JOIN module ON AC.moduleid = module.id
            $where");
    while ($db->next_record()) 
        $colls[$db->f("collectionid")] = $db->Record;
    if (!is_array ($colls)) return;
    
    reset ($colls);
    while (list ($cid, $collection) = each ($colls)) {
        unset ($users);
        if (is_array ($emails)) {
            reset ($emails);
            while (list (,$email) = each ($emails)) 
                $users[] = array ("email" => $email, "allfilters" => 1);
        }
        else {
            // find users with all filters
            $db->query ("
                SELECT U.*, UC.allfilters FROM 
                alerts_user U INNER JOIN 
                alerts_user_collection UC ON U.id = UC.userid 
                WHERE U.confirm=''
                AND UC.howoften = '$ho'
                AND UC.collectionid = $cid");
          
            while ($db->next_record()) {
                $users[$db->f("id")]["email"] = email_address ($db->f("firstname")." ".$db->f("lastname"), $db->f("email"));
                $users[$db->f("id")]["allfilters"] = $db->f("allfilters");
            }    

            // find users with some filters
            $db->query ("
                SELECT UCF.* FROM 
                alerts_user U INNER JOIN 
                alerts_user_collection UC ON U.id = UC.userid INNER JOIN
                alerts_user_collection_filter UCF ON U.id = UCF.id AND UC.collectionid = UCF.collectionid
                WHERE U.confirm=''
                AND UC.howoften = '$ho'                
                AND UC.collectionid = $cid");

            while ($db->next_record())
                $users[$db->f("userid")]["filters"][$db->f("myindex")] = $db->f("filterid");
        }

        // find filters for this collection
        $db->query ("SELECT HF.* FROM alerts_collection_filter CF
            INNER JOIN alerts_filter_howoften HF ON CF.filterid = HF.filterid
            WHERE HF.howoften = '$ho' AND CF.collectionid = $cid
            ORDER BY CF.myindex");

        unset ($allfilters);
        while ($db->next_record()) {
            $filters[$db->f("filterid")] = $db->f("text");
            $allfilters .= $db->f("text");
        }    

        // create MIME headers
        $headers = alerts_email_headers ($collection, $default_collection);

        reset ($users);
        while (list ($uid, $user) = each ($users)) {
            if ($user["allfilters"])
                $filtertext = $allfilters;
            else {
                $user_filters = $user["filters"];
                ksort ($user_filters);
                reset ($user_filters);
                $filtertext = "";
                while (list (,$fid) = each ($user_filters))
                    $filtertext .= $filters[$fid];
            }
//            $url = AA_INSTAL_URL."au.php3?u=$uid&l=".get_mgettext_lang();
//            $footer = "-----------------------------------------------------------------------<br>"
//                . _m("You can change your subscriptions on")."<br>\n<a href='$url'>$url</a>";

            $alias["_#FILTERS_"] = $filtertext;
            $alias["_#HOWOFTEN"] = $ho;
            $alias["_#COLLFORM"] = $collection["slice_url"]."?uid=$uid";
            $alias["_#USER_SET"] = AA_INSTAL_URL."au.php3?u=$uid";

            send_mail_from_table ($collection["emailid_alert"], $user["email"], $alias); 
        }
        $email_count += count ($users);
    }
    
    return $email_count;
}

// -------------------------------------------------------------------------------------
/**  Initializes the "last" field (which tells when were the Alerts last time sent)
*   for all unfilled filter / howoften combinations 
*   to a value depending on howoften, i.e. to one week ago for weekly etc.
*/    
function initialize_filters ()
{
    global $db;
    $now = getdate ();
    $init["instant"] = time();
    // one day ago
    $init["daily"] = mktime ($now[hours], $now[minutes], $now[seconds], $now[mon], $now[mday]-1, $now[year]);
    // one week (7 days) ago
    $init["weekly"] = mktime ($now[hours], $now[minutes], $now[seconds], $now[mon], $now[mday]-7, $now[year]);
    // one month ago
    $init["monthly"] = mktime ($now[hours], $now[minutes], $now[seconds], $now[mon]-1, $now[mday], $now[year]);
    
    $db2 = new DB_AA;
 
    $hos = get_howoften_options();   
    reset ($hos);
    while (list ($ho) = each ($hos)) {
        $db->query ("SELECT F.id FROM alerts_filter F LEFT JOIN
            alerts_filter_howoften FH ON FH.filterid = F.id AND howoften='$ho'
            WHERE last IS NULL");
        while ($db->next_record()) 
            $db2->query ("INSERT INTO alerts_filter_howoften (filterid, howoften, last)
                VALUES (".$db->f("id").", '$ho', ".$init[$ho].")");

        $db->query ("SELECT F.id FROM alerts_filter F INNER JOIN
            alerts_filter_howoften FH ON FH.filterid = F.id
            WHERE last=0 AND howoften='$ho'");
        while ($db->next_record()) 
            $db2->query ("UPDATE alerts_filter_howoften SET last=".$init[$ho]
                ." WHERE filterid=".$db->f("id")." AND howoften='$ho'");
    }
}           

?>

