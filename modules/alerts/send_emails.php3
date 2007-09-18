<?php
/**
 * Alerts menu: Send emails.
 * Functions for showing info and allowing to send an example email to the user.
 *
 * @package Alerts
 * @version $Id$
 * @author Jakub Adámek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications
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

// (c) Econnect, Jakub Adamek, December 2002

require_once "alerts_sending.php3";

function showCollectionAddOns() {
    global $example, $fire, $auth, $cmd, $db, $sess, $collectionprop, $collectionid;

    initialize_last();

    $collection = new AA_Collection($collectionid);

    if (!$example["howoften"]) {
        $example["howoften"] = "weekly";
    }
    if (!$example["howoftenr"]) {
        $example["howoftenr"] = "weekly";
    }
    if (!$example["email"]) {
        $me = GetUser($auth->auth["uid"]);
        $example["email"] = $me["mail"][0];
    }

    echo "<br><br>\n    <form name=\"example[form]\" method=\"post\">";
    FrmTabCaption( _m('Send now an example alert email to')) ;

    if ($example["go"]) {
        $mail_count = send_emails($example["howoften"], array($collectionid),  array($example["email"]), false, "");
    } elseif ($example["goreader"]) {
        $mail_count = send_emails($example["howoftenr"], array($collectionid),  '', false, "", $example['reader']);
    }

    // send to supplied email
    FrmInputText("example[email]", _m('Email'), $example['email']);
    FrmInputSelect("example[howoften]", _m("as if"), get_howoften_options(true), $example["howoften"]);
    FrmTabSeparator("", array('example[go]' => array('type'=> 'submit', 'value'=>_m("Go!"))));

    // send to selected user
    FrmInputSelect("example[reader]",_m('Reader'), $collection->getReadersSelectArray(true), $example["reader"]);
    FrmInputSelect("example[howoftenr]", _m("as if"), get_howoften_options(true), $example["howoftenr"]);
    FrmTabEnd(array('example[goreader]' => array('type'=> 'submit', 'value'=>_m("Go!"))));
    echo '</form>';

    // SEND EMAILS

    echo '<br>
    <form name="fire_form" method="post" action="'.$sess->url($_SERVER["PHP_SELF"]).'">';
    FrmTabCaption( _m('Send alerts'));

    if ($fire["fire"]) {
        $mail_count = send_emails($fire["howoften"], array ($collectionid), "all", true, "");
    }


    FrmInputSelect("fire[howoften]",_m('Send now alerts to all users subscribed to '), get_howoften_options(true), $fire["howoften"], _m("Warning: This is a real command!"));
    FrmTabEnd(array('fire[fire]' => array('type'=> 'submit', 'value'=>_m("Go!"))));
    echo '<br>';

    FrmTabCaption( _m("Last time the alerts were sent on:"));
    $last = GetTable2Array("SELECT howoften, last FROM alerts_collection_howoften WHERE collectionid='".$collectionid."'", 'howoften', 'last');

    foreach (get_howoften_options(true) as $ho => $msg) {
        FrmStaticText($msg,  date("j.n. H:i", $last[$ho]));
    }
    FrmTabEnd();
    echo "</form>\n";

    if ($fire["fire"] || $example["go"] || $example["goreader"]) {
        echo "<br><b>"._m("%1 email(s) sent", array ($mail_count+0))."</b>\n";
    }

}

function showSelectionTable() {
    global $sess;
    $db = new DB_AA;
    $db->query (
        "SELECT view.slice_id, slice.name AS slice_name,
            alerts_filter.vid, view.name AS view_name,
            alerts_filter.id AS fid, view.type AS view_type,
            alerts_filter.description AS filter_name
         FROM slice INNER JOIN view ON slice.id = view.slice_id
         INNER JOIN alerts_filter ON alerts_filter.vid = view.id
         ORDER BY slice.name, view.name, alerts_filter.id");
    $myslices = GetUserSlices();
    while ($db->next_record()) {
        $a[$db->f("slice_id")]["name"] = $db->f("slice_name");
        $av = &$a[$db->f("slice_id")]["views"];
        $av[$db->f("vid")]["name"] = $db->f("view_name");
        $av[$db->f("vid")]["type"] = $db->f("view_type");
        $av[$db->f("vid")]["filters"][$db->f("fid")] = $db->f("filter_name");
    }

    //echo "<BR><B>"._m("Selections ordered by slice and view:")."</B><BR>";
    echo "<BR><TABLE border=1 cellpadding=3 cellspacing=0>
        <TR class=tabtit><TD><B>"._m("Slice")."</B></TD>
        <TD><B>"._m("View (Selection set)")."</B></TD>
        <TD><B>"._m("Selections")."</B></TD></TR>";

    if (!is_array($a)) {
        echo "<TR><TD colspan=3 align=center class=tabtxt><B>"
        ._m("Define selections in slices from which you want to send Alerts,
        in views of type Alerts Selection Set")."</B></TD></TR>";
    } else {
        foreach ($a as $slice_id => $slice) {
            if (! IsSuperadmin() && ! strchr ($myslices [unpack_id128($slice_id)], PS_FULLTEXT)) {
                continue;
            }
            echo "<TR><TD class=tabtxt rowspan=".count($slice["views"]).">
                <A href=\"".$sess->url(AA_INSTAL_PATH
                    ."admin/index.php3?slice_id=".unpack_id($slice_id))."\">"
                    .$slice["name"]."</A></TD>";
            $first_view = true;
            foreach ($slice["views"] as $vid => $view) {
                if (! $first_view) {
                    echo "<TR>";
                }
                $first_view = false;
                echo "<TD class=tabtxt>
                    <a href=\"".$sess->url(AA_INSTAL_PATH
                    ."admin/se_view.php3?slice_id=".unpack_id128($slice_id)
                    ."&view_id=".$vid."&view_type=".$view["type"])
                    ."\">".$view["name"]."</A></TD><TD class=tabtxt>";
                foreach ($view["filters"] as $fid => $filter) {
                    echo "f".$fid." ".$filter."<br>";
                }
                echo "</TD></TR>";
            }
        }
    }
    echo "</TABLE>";
}

?>