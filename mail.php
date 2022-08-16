<?php
//$Id$
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

/**
 *  @param form_id   - long id of form
 *  @param type      - "ajax"
 *  @param ret_code  - AA expression (with aliases to be returned after ajax insert)
 *  @param object_id - optional item_id (for edit)
 */

require_once __DIR__."/./include/config.php";
require_once __DIR__ . "/include/util.php";
require_once __DIR__ . "/include/item.php";
require_once __DIR__ . "/include/searchlib.php";
require_once __DIR__ . "/include/locsess.php";    // DB_AA object definition

if (!$_POST['aa_mailconf']) {
    echo '<!-- not configured -->';
    exit;
}

$config_arr = AA_Stringexpand_Encrypt::decrypt_time_token($_POST['aa_mailconf'],24);
if (!$config_arr) {
    echo '<!-- wrong token (old?) -->';
    exit;
}

$inner_body = '';
foreach ($_POST as $k => $v) {
    if (in_array($k, ['answer', 'aa_mailconf'])) {
        continue;
    }
    $inner_body .= "<br><b>$k</b><br>$v<br><br>\n";
}

if (!strlen($inner_body)) {
    echo '<!-- no data to send -->';
    exit;
}

$to = json2arr($config_arr['to']); // can't be inside empty()  - Honza, php 5.2
if (empty($to)) {
    echo '<!-- no recipient -->';
    exit;
}

$body = strlen(trim($config_arr['body'])) ? str_replace('_#1', $inner_body, $config_arr['body']) : $inner_body;
$mail_arr = [
    'subject'     => $config_arr['subject'],
                   'body'        => $body,
                   'header_from' => $config_arr['from'],
                   'lang'        => $config_arr['lang'],
                   'html'        => 1
];

$mail = new AA_Mail;
$mail->setFromArray($mail_arr);
$mail->sendLater($to);

echo $config_arr['ok'];


