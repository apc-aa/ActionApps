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

require "util.php3";
require "../../include/util.php3";

add_vars ();

$cf_fields = array (
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
        "label"=>_m("How often"))
);
?>
