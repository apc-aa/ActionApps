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

if (!is_array($_PHPLIB)) {
  $_PHPLIB["libdir"] = "";
}

if (! PHPLIB_ALREADY_LOADED && ! defined ("PHPLIB_AA_LOADED")) {
  require($_PHPLIB["libdir"] . "db_mysql.inc");  
  require($_PHPLIB["libdir"] . "ct_sql.inc");    
  require($_PHPLIB["libdir"] . "session.inc");   
  require($_PHPLIB["libdir"] . "auth.inc");      
  require($_PHPLIB["libdir"] . "page.inc");
}
define ("PHPLIB_AA_LOADED", 1);

require($GLOBALS[AA_BASE_PATH] . "modules/alerts/uc_sess.php3");     
  
class AA_UC_Auth extends Auth {
  var $classname      = "AA_UC_Auth";
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
    global $sess, $_PHPLIB, $HTTP_POST_VARS, $anonymous_user;
    $username = $HTTP_POST_VARS["username"];  # there was problem with variables
    $password = $HTTP_POST_VARS["password"];  # in cookies - if someone writes 
                                              # to cookies username, then the 
                                              # cookies username is used - error
    
    require ($GLOBALS["AA_BASE_PATH"] . "modules/alerts/uc_index.php3");
  }

  function auth_validatelogin() {
    global $HTTP_POST_VARS;
    $email = $HTTP_POST_VARS["email"];  # there was problem with variables
    $password = $HTTP_POST_VARS["password"];  # in cookies - if someone writes 
                                              # to cookies username, then the 
                                              # cookies username is used - error
    if(isset($username))
      $this->auth["uname"]=$username;

    global $db;
    $db = new DB_AA;
    $db->query ("SELECT * FROM alerts_user WHERE email = '$email'");
    $uid = false;
    if ($db->next_record() &&
       ($db->f("password") == "" || md5 ($password) == $db->f("password")))
        $uid = $db->f("id");
    return $uid;
  }
};  
?>