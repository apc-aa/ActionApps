<?php
/**
 * Alerts - Collection Forms
 * Defines the $cf_fields array used in Collection Form handling scripts.
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

require_once "util.php3";
require_once "../../include/util.php3";
require_once "../../include/formutil.php3";

add_post2shtml_vars();

global $LANGUAGE_CHARSETS;
reset ($LANGUAGE_CHARSETS);
while (list ($l) = each ($LANGUAGE_CHARSETS))
    $langs[$l] = $l;

$cf_fields = array (
    "lang" => array (
        "code"=>FrmSelectEasyCode ("formlang", $langs, get_mgettext_lang()),
        "label"=>_m("Language"),
        // do not show in wizard
        "hidewizard"=>true),
    "email" => array (
        "code"=>"<INPUT type=\"text\" name=\"alerts[email]\" size=\"40\">",
        "label"=>_m("Email"),
        "required"=>true,
        "userinfo"=>true),
    "password" => array (
        "code"=>"<INPUT type=\"password\" name=\"alerts[password]\" size=\"20\">",
        "label"=>_m("Password"),
        "required"=>true),
    "firstname" => array (
        "code"=>"<INPUT type=\"text\" name=\"alerts[firstname]\" size=\"20\">",
        "label"=>_m("First name"),
        "userinfo"=>true),
    "lastname" => array (
        "code"=>"<INPUT type=\"text\" name=\"alerts[lastname]\" size=\"25\">",
        "label"=>_m("Last name"),
        "userinfo"=>true),
    "howoften" => array (
        "code"=>FrmSelectEasyCode ("alerts[howoften]", get_howoften_options ()),
        "required"=>true,
        // do not show in user center
        "hidecenter"=>true,
        "label"=>_m("How often")),
    "chpwd" => array (
        "code"=>"<INPUT type=\"password\" name=\"alerts[chpwd]\">",
        "required"=>false,
        // the checkbox is valid for both this and the next row
        "rowspan"=>2,
        "label"=>_m("Change password")),
    "chpwd2" => array (
        "code"=>"<INPUT type=\"password\" name=\"alerts[chpwd2]\">",
        // the checkbox from chpwd is valid for this row too
        "checkbox"=>"chpwd",
        "label"=>_m("Retype new password"))
);
?>
