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

class AA_SL_Session extends Session {

    var $classname = "AA_SL_Session"; // Session name
 // var $id = "";                     // Current session id
 // var $name = "";                   // [Current] Session name
 // var $cookie_path = '/';
 // var $cookiename = "";
 // var $lifetime = 0;
 // var $cookie_domain = '';          // If set, the domain for which the session cookie is set.

    /**
    * Propagation mode is by default set to cookie
    * The other parameter, fallback_mode, decides wether
    * we accept ONLY cookies, or cookies and eventually get params
    * in php4 parlance, these variables cause a setting of either
    * the php.ini directive session.use_cookie or session.use_only_cookie
    * The session.use_only_cookie possibility was introdiced in PHP 4.2.2, and
    * has no effect on previous versions
    *
    * @var    string
    * @deprec $Id$
    */
 // var $mode = "cookie";               // We propagate session IDs with cookies

    /**
    * If fallback_mode is set to 'cookie', php4 will impose a cookie-only
    * propagation policy, which is a safer  propagation method that get mode
    *
    * @var    string
    * @deprec $Id$
    */
 // var $fallback_mode;                 // if fallback_mode is also 'cookie'
                                        // we enforce session.use_only_cookie

 // var $allowcache = 'nocache';   // See the session_cache_limit() options

    /**
    * Do we need session forgery check?
    * This check prevents from exploiting SID-in-request vulnerability.
    * We check the user's last IP, and start a new session if the user
    * has no cookie with the SID, and the IP has changed during the session.
    * We also start a new session with the new id, if the session does not exists yet.
    * We don't check cookie-enabled clients.
    * @var boolean
    */
 // var $forgery_check_enabled = false;


    /** MyUrl function
     * @param $SliceID
     * @param $Encap
     * @param $noquery
     */
    function MyUrl($SliceID, $Encap=false, $noquery=false) {
        global $scr_url;
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
            // You will need to fix suexec as well, if you use Apache and CGI PHP
            $PROTOCOL='https';
        } else {
            $PROTOCOL='http';
        }
        // PHP used in CGI mode and ./configure --enable-force-cgi-redirect
        if ($scr_url) {  // if included into php script
            $foo = $PROTOCOL. "://". $_SERVER['HTTP_HOST'].$scr_url;
        } elseif (isset($_SERVER['REDIRECT_SCRIPT_NAME'])) {
            $foo = $PROTOCOL. "://". $_SERVER['HTTP_HOST'].$_SERVER['REDIRECT_SCRIPT_NAME'];
        } else {
            $foo = $PROTOCOL. "://". $_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'];
        }

        switch ($this->mode) {


            // not executed - mode is cookie. Could be removed (Honza 16-03-31)
            case "get":
                if (!$noquery) {
                    $foo .= "?slice_id=$SliceID";
                    $foo .= ($Encap?"":"&encap=false");
                    $foo .= "&".urlencode($this->name)."=".$this->id;
                }
                break;
            default:
                break;
        }
        return $foo;
    }
}

class AA_CP_Session extends Session {
    var $classname = "AA_CP_Session";

    // add module_id=... to url. It is better to use StateUrl() directly, but we already use $sess->url() from older versions of $session management
    function url($url) {
        return StateUrl($url);
    }

    // get <input name="module_id"... . It is better to use StateHidden() directly, but we already use $sess->hidden_session() from older versions of $session management
    function get_hidden_session() {
        return StateHidden();
    }
}

?>
