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

require_once $GLOBALS["AA_INC_PATH"]."util.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";

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
        
        require_once ($GLOBALS["AA_BASE_PATH"] . "modules/alerts/uc_index.php3");
    }
    
    function auth_preauth() {
        if ($GLOBALS["confirm_id"]) 
            return $this->auth_confirm_collection();
        if ($GLOBALS["single_usage_key"])
            return $this->auth_single_usage_key();
    } 

    /** Uses the single usage key to log the user in. */
    function auth_single_usage_key () {
        global $single_usage_key, $Msg, $db;
        $db->query("SELECT id FROM alerts_user WHERE single_usage_access_key='$single_usage_key'");
        $uid = false;
        if ($db->next_record()) {
            $uid = $db->f("id");
            $db->query("UPDATE alerts_user SET single_usage_access_key = NULL WHERE id=$uid");
        }
        else $GLOBALS["Err"][] = _m("This key was already used. It is no more valid.");
        return $uid;
    }

    /** Confirms subscription to a collection and automatically logs the user in. */
    function auth_confirm_collection () {
        global $confirm_id, $Msg, $db;
     
        // clicking on confirmation link in email
        $db->query("SELECT confirm,email,userid,collectionid FROM alerts_user AU
            INNER JOIN alerts_user_collection AUC ON AU.id = AUC.userid
            WHERE confirm='$confirm_id'");
        if ($db->next_record()) {
            $uid = $db->f("userid");
            $cid = $db->f("collectionid");
            $db->query("SELECT confirmed_status_code FROM alerts_collection WHERE id=$cid");
            $db->next_record();
            $varset = new CVarset;
            $varset->addkey ("userid", "number", $uid);
            $varset->addkey ("collectionid", "number", $cid);
            $varset->add ("confirm", "text", "");
            $varset->add ("status_code", "number", $db->f("confirmed_status_code"));
            $db->query($varset->makeUPDATE ("alerts_user_collection"));
            
            $Msg = _m("Congratulations. Your subscription was confirmed.");        
        }   
        else {
            $Msg = _m("Your code is not valid any more. Please subscribe again.");
            $uid = false;
        }
        return $uid;
    }    

    function auth_validatelogin() {
        global $HTTP_POST_VARS;
        $email = $HTTP_POST_VARS["email"];  # there was problem with variables
        $password = $HTTP_POST_VARS["password"];  # in cookies - if someone writes 
                                              # to cookies username, then the 
                                              # cookies username is used - error
                                              
        if(isset($username))
            $this->auth["uname"]=$username;

        global $db, $Err, $send_single_usage_code;
        if ($send_single_usage_code)
            send_single_usage_code();
        else {            
            $db = new DB_AA;
            $db->query("SELECT * FROM alerts_user WHERE email = '$email'");
            $uid = false;
            if (!$db->next_record()) 
                $Err[] = _m("This email is not subscribed to any Collection.");
            else if ($db->f("password") != "" && md5 ($password) != $db->f("password"))
                $Err[] = _m("Wrong email or password.");
            else $uid = $db->f("id");
            return $uid;
        }
    }
};  
?>