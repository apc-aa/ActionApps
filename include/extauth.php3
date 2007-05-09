<?php
/**
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   UserInput
 * @version   $Id$
 * @author    Jiri Hejsek, Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (c) 2002-3 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once(AA_INC_PATH . "perm_core.php3"); // allways included!

// choice of the permission system library
// PERM_LIB is defined in config.php3

require_once(AA_INC_PATH . "perm_" . PERM_LIB . ".php3");

// Required, contains your local session management extension

class AA_CP_Auth extends Auth {
  var $classname      = "AA_CP_Auth";
  var $lifetime       =  200;                // 200 minutes
//  var $database_class = "DB_AA";
//  var $database_table = "auth_user";
    /** relogin_if function
     * @param $t
     */
    function relogin_if($t) {
        if ( $t )  {
            printf ("<center><b>User ".$this->auth["uname"]." has been logged out.</b></center><br>");
            $this->unauth();
            $this->start();
        }
    }
    /** auth_loginform
     *
     */
    function auth_loginform() {
        global $sess, $_PHPLIB, $HTTP_POST_VARS, $anonymous_user;
        $username = $HTTP_POST_VARS["username"];  // there was problem with variables
        $password = $HTTP_POST_VARS["password"];  // in cookies - if someone writes
                                                  // to cookies username, then the
                                                  // cookies username is used - error

        require_once (AA_INC_PATH . "loginform.inc");
    }
    /** auth_validatelogin function
     *
     */
    function auth_validatelogin() {
        global $HTTP_POST_VARS;
        $username = $HTTP_POST_VARS["username"];  // there was problem with variables
        $password = $HTTP_POST_VARS["password"];  // in cookies - if someone writes
                                                // to cookies username, then the
                                                // cookies username is used - error
        if (isset($username)){
            $this->auth["uname"]=$username;
        }

        $user=$username;
        $uid = AuthenticateUsername($user, $password);
        return $uid;
    }
};
?>