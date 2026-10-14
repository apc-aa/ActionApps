<?php

/**
 * Alerts menu: Send emails.
 * Functions for showing info and allowing to send an example email to the user.
 *
 * @package Alerts
 * @version $Id$
 * @author Jakub Adamek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

use AA\IO\DB\DB_AA;

require_once __DIR__ . "/alerts_sending.php";

function ShowCollectionAddOns(): void
{
    global $example, $fire, $collectionid, $mgettext_domain;

    initialize_last();

    // send_emails() rebinds gettext to each slice's own language (get_filter_output_cached()
    // -> mgettext_bind), which would otherwise leak into the rest of this admin page. Remember
    // the admin UI domain and restore it after every send.
    $admin_lang_domain = $mgettext_domain;

    $collection = new AA_Collection($collectionid);

    if (!$example["howoften"]) {
        $example["howoften"] = "weekly";
    }
    if (!$example["howoftenr"]) {
        $example["howoftenr"] = "weekly";
    }
    if (!$example["email"]) {
        $me = AA::$perm->getIDsInfo(AuthGetUid());
        $example["email"] = $me["mail"];
    }

    $mail_count = 0;

    // send to supplied email (own form)
    echo "<br><br>\n    <form name=\"example_email_form\" method=\"post\">";
    FrmTabCaption(_m('Send now an example alert email to email'), [], true) ;

    if ($example["go"]) {
        $mail_count = send_emails($example["howoften"], [$collectionid], [$example["email"]], false, "");
        bind_mgettext_domain($admin_lang_domain, true);
    }

    FrmInputEmail("example[email]", _m('Email'), $example['email'], 254, 25, true);
    FrmInputSelect("example[howoften]", _m("as if"), get_howoften_options(), $example["howoften"], true);
    FrmTabEnd(['example[go]' => ['type' => 'submit', 'value' => _m("Go!")]]);
    echo '</form>';

    echo '<br>';

    // send to selected reader (own form)
    $reader_mails = $collection->getReadersSelectArray();
    asort($reader_mails);

    echo '<form name="example_reader_form" method="post">';
    FrmTabCaption(_m('Send now an example alert email to Reader'), [], true) ;

    if ($example["goreader"]) {
        $mail_count = send_emails($example["howoftenr"], [$collectionid], [], false, "", $example['reader']);
        bind_mgettext_domain($admin_lang_domain, true);
    }

    FrmInputSelect("example[reader]", _m('Reader'), $reader_mails, $example["reader"], true);
    FrmInputSelect("example[howoftenr]", _m("as if"), get_howoften_options(), $example["howoftenr"], true);
    FrmTabEnd(['example[goreader]' => ['type' => 'submit', 'value' => _m("Go!")]]);
    echo '</form>';

    // SEND EMAILS (fire to all - own form)

    echo '<br>
    <form name="fire_form" method="post" action="' . StateUrl($_SERVER["PHP_SELF"]) . '">';
    FrmTabCaption(_m('Send alerts'));

    if ($fire["fire"]) {
        $mail_count = send_emails($fire["howoften"], [$collectionid], "all", true, "");
        bind_mgettext_domain($admin_lang_domain, true);
    }


    FrmInputSelect("fire[howoften]", _m('Send now alerts to all users subscribed to '), get_howoften_options(), $fire["howoften"], _m("Warning: This is a real command!"));
    FrmTabEnd(['fire[fire]' => ['type' => 'submit', 'value' => _m("Go!")]]);
    echo '<br>';

    FrmTabCaption(_m("Last time the alerts were sent on:"));
    $last = DB_AA::select(['howoften' => 'last'], "SELECT howoften, last FROM alerts_collection_howoften", [['collectionid', $collectionid]]);

    $ho_options = get_howoften_options();
    foreach ($ho_options as $ho => $msg) {
        FrmStaticText($msg, $last[$ho] ? date("j.n.Y H:i", $last[$ho]) : '--');
    }
    FrmTabEnd();
    echo "</form>\n";

    if ($fire["fire"] || $example["go"] || $example["goreader"]) {
        echo "<br><b>" . _m("%1 email(s) sent", [$mail_count + 0]) . "</b>\n";
    }

}

function showSelectionTable(): void
{
    $rows = DB_AA::select(
        [],
        "SELECT view.slice_id, slice.name AS slice_name,
            alerts_filter.vid, view.name AS view_name,
            alerts_filter.id AS fid, view.type AS view_type,
            alerts_filter.description AS filter_name
         FROM slice INNER JOIN view ON slice.id = view.slice_id
         INNER JOIN alerts_filter ON alerts_filter.vid = view.id
         ORDER BY slice.name, view.name, alerts_filter.id",
    );
    $myslices = GetUserSlices();
    foreach ($rows as $row) {
        $a[$row["slice_id"]]["name"] = $row["slice_name"];
        $av = &$a[$row["slice_id"]]["views"];
        $av[$row["vid"]]["name"] = $row["view_name"];
        $av[$row["vid"]]["type"] = $row["view_type"];
        $av[$row["vid"]]["filters"][$row["fid"]] = $row["filter_name"];
    }

    //echo "<BR><B>"._m("Selections ordered by slice and view:")."</B><BR>";
    echo "<br><table>
        <tr class=\"aa-tbl-header\"><th>" . _m("Slice") . "</th>
        <th>" . _m("View (Selection set)") . "</th>
        <th>" . _m("Selections") . "</th></tr>";

    if (!is_array($a)) {
        echo "<tr><td colspan=3><b>"
        . _m("Define selections in slices from which you want to send Alerts,
        in views of type Alerts Selection Set") . "</b></td></tr>";
    } else {
        foreach ($a as $slice_id => $slice) {
            if (! IsSuperadmin() && !str_contains($myslices[unpack_id($slice_id)], PS_FULLTEXT)) {
                continue;
            }
            echo "<tr><td rowspan=" . count($slice["views"]) . ">
                <a href=\"" . StateUrl(AA_INSTAL_PATH
                    . "admin/index.php?slice_id=" . unpack_id($slice_id)) . "\">"
                    . $slice["name"] . "</a></td>";
            $first_view = true;
            foreach ($slice["views"] as $vid => $view) {
                if (! $first_view) {
                    echo "<tr>";
                }
                $first_view = false;
                echo "<td>
                    <a href=\"" . StateUrl(AA_INSTAL_PATH . "admin/se_view.php?slice_id=" . unpack_id($slice_id) . "&view_id=" . $vid . "&view_type=" . $view["type"]) . "\">" . $view["name"] . "</a></td><td>";
                foreach ($view["filters"] as $fid => $filter) {
                    echo "f" . $fid . " " . $filter . "<br>";
                }
                echo "</td></tr>";
            }
        }
    }
    echo "</table>";
}
