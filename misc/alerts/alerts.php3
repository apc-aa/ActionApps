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

/* Alerts sending
       $lang - set language
       $howoften - which items to send
*/


require "./lang.php3";
require $GLOBALS[AA_INC_PATH]."item.php3";
require $GLOBALS[AA_INC_PATH]."view.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."searchlib.php3";

//$debug = 1;

$howoften_digest = array (
    "daily"=>_m("AA Alerts - daily digest of "),
    "weekly"=>_m("AA Alerts - weekly digest of "),
    "monthly"=>_m("AA Alerts - monthly digest of "));        
      
$db = new DB_AA;

// -------------------------------------------------------------------------------------
/*  Function: create_filter_text
    Purpose:  finds and formats items, writes the result into alerts_digest_filter.$ho
    WARNING:  the function does not check when were the items sent last time, call it only once a day / week / month
    Parameters: $ho = how often (daily / weekly / monthly)
*/

function create_filter_text ($ho)
{
    global $debug, $howoften_options, $db, $conds, $sort;
    
    echo "<h1>$ho</h1>";

    // first I create a hierarchical array $slices and than use it instead of the recordset
            
    $db->query("SELECT DF.conds, view.slice_id, DF.id AS filterid, DF.vid, slice.name AS slicename, slice.lang_file, DF.last_$ho
        FROM alerts_digest_filter DF INNER JOIN
             view ON view.id = DF.vid INNER JOIN
             slice ON slice.id = view.slice_id
        ORDER BY view.slice_id, DF.vid");
    
    while ($db->next_record()) {
        $slices[$db->f("slice_id")]["name"] = $db->f("slicename");
        $slices[$db->f("slice_id")]["lang_file"] = $db->f("lang_file");
        $slices[$db->f("slice_id")]["views"][$db->f("vid")]["filters"][$db->f("filterid")] = 
            array ("conds"=>$db->f("conds"), "last"=>$db->f("last_$ho"));
    }
    
    reset ($slices);
    while (list ($p_slice_id, $slice) = each ($slices)) {
        $slice_id = unpack_id ($p_slice_id);
        list($fields,) = GetSliceFields($slice_id);
        $aliases = GetAliasesFromFields($fields, $als);       
        // set language
        set_mgettext_domain ($db->f("lang_file"));
        
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
            
   The field moved2active is filled whenever an item is moved from other bin to Active bin or when it is a new item inserted into Active bin. The field is cleared whenever the item is moved from Active bin to another one.
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
                if (!$debug) 
                    $db->query ("UPDATE alerts_digest_filter 
                                 SET last_$ho = $now 
                                 WHERE id = $fid");
                    
                $dbconds = $filter["conds"];
                if ($debug) echo "<br>Slice ".$slice["name"].", conds ".$dbconds."<br>";       
                add_vars ($dbconds);
                if ($debug) { print_r ($conds); echo "<br>"; }
        
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
                
                $db->query ("UPDATE alerts_digest_filter 
                             SET text_$ho = '".addslashes ($items_text)."'
                             WHERE id = $fid");  
            }
        }
    }
}

// -------------------------------------------------------------------------------------
    
function send_emails ($ho)
{
    global $debug, $db, $conds;
            
    $dbtexts = new DB_AA;
        
    $db->query ("SELECT * FROM alerts_user WHERE confirm=''");
    while ($db->next_record()) {
        $dbtexts->tquery ("
            SELECT C.description, CF.collectionid, CF.myindex, DF.text_$ho FROM 
            alerts_collection C INNER JOIN
            alerts_collection_filter CF ON C.id = CF.collectionid INNER JOIN 
            alerts_digest_filter DF ON CF.filterid = DF.id INNER JOIN 
            alerts_user_filter UF ON UF.collectionid = C.id
            WHERE UF.userid = ".$db->f("id")."
              AND DF.text_$ho <> ''
              AND UF.howoften = '$ho'");
            
        while ($dbtexts->next_record()) {
            $colls[$dbtexts->f("collectionid")]["desc"] = $dbtexts->f("description");
            $colls[$dbtexts->f("collectionid")]["filters"][$dbtexts->f("myindex")] = $dbtexts->f("text_$ho");
        }
        
        $email = email_address ($db->f("firstname")." ".$db->f("lastname"), $db->f("email"));
        $url = AA_INSTAL_URL."misc/alerts?show_email=".$db->f("email");
        $headers = "From: ".ALERTS_EMAIL."\n";    
        $footer = "-----------------------------------------------------------------------<br>"
            . _m("You can change your subscriptions on %1.", array ("<a href='$url'>$url</a>."));

        if (is_array ($colls)) {
            reset ($colls);
            while (list ($cid, $collection) = each ($colls)) {
                $mailbody = "";
                reset ($collection["filters"]);
                while (list (,$text) = each ($collection["filters"]))
                    $mailbody .= $text;           
                $mailbody .= $footer;
                mail_html_text ($email, $GLOBALS[howoften_digest][$ho] . $collection["desc"], $mailbody, $headers, _m("CHARSET"), 0);
                $email_count ++;
            }
        }
    }
    
    echo "<br>Count of emails sent is <b>".($email_count+0)."</b><br>";
}

// -------------------------------------------------------------------------------------

function initialize_filters ()
{
    global $db;
    $now = getdate ();
    $daily = mktime ($now[hours], $now[minutes], $now[seconds], $now[mon], $now[mday]-1, $now[year]);
    $weekly = mktime ($now[hours], $now[minutes], $now[seconds], $now[mon], $now[mday]-7, $now[year]);
    $monthly = mktime ($now[hours], $now[minutes], $now[seconds], $now[mon]-1, $now[mday], $now[year]);
    $db->query ("
        UPDATE alerts_digest_filter 
        SET last_daily = $daily,
            last_weekly = $weekly,
            last_monthly = $monthly
        WHERE last_daily = 0");
}           

// -------------------------------------------------------------------------------------

#reset ($howoften_options);
#while (list ($ho) = each ($howoften_options)) {

if ($howoften_options[$howoften]) {
    initialize_filters();
    create_filter_text ($howoften);
    send_emails ($howoften);
}

?>

