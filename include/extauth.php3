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

require($GLOBALS[AA_INC_PATH] . "perm_core.php3"); // allways included!

// choice of the permission system library
// PERM_LIB is defined in config.php3

require($GLOBALS[AA_INC_PATH] . "perm_" . PERM_LIB . ".php3");

// Required, contains your local session management extension

class AA_CP_Auth extends Auth {
  var $classname      = "AA_CP_Auth";
  var $lifetime       =  200;                // 200 minutes
//  var $database_class = "DB_AA";
//  var $database_table = "auth_user";
  
  function relogin_if( $t ) {
    if ( $t )  {
      printf ("<center><b>User ".$this->auth["uname"]." has been logged out.</b></center><br>");
      $this->unauth();
      $this->start();
    }
  }
  
  function auth_loginform() {
    global $sess;
    global $_PHPLIB;
    global $username, $password, $anonymous_user;
    require ($GLOBALS[AA_INC_PATH] . "loginform.html");
  }
  
  function auth_validatelogin() {
    global $username, $password;

    if(isset($username)) 
      $this->auth["uname"]=$username;
  
    $user=$username;
      
    $uid = AuthenticateUsername($user, $password);
    return $uid;
  }  
}
/*
$Log$
Revision 1.2  2001/02/26 17:22:30  honzam
color profiles, itemmanager interface changes

Revision 1.1.1.1  2000/06/21 18:40:36  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:21  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.15  2000/06/12 19:58:35  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.14  2000/06/09 15:14:11  honzama
New configurable admin interface

Revision 1.13  2000/04/24 16:48:29  honzama
New anonymous posting of items.

Revision 1.12  2000/03/29 15:54:47  honzama
Better Netscape Navigator javascript support, new direct feeding support, minor changes in texts and look.

Revision 1.11  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>