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


/** DeBackslash2 function
 *  skips terminating backslashes
 * @param $txt
 * @return string
 */
function DeBackslash2($txt) {
    return str_replace('\\', "", $txt);        // better for two places
}


class AA_SL_Session extends Session {
    var $classname = "AA_SL_Session";

    var $cookiename     = "";                // defaults to classname
    var $magic          = "adwetdfgyr";      // ID seed
    var $mode           = "get";             // We propagate session IDs via cookie method
    var $fallback_mode  = "get";             // If cookie not possible, then via get method
    var $lifetime       = 0;                 // 0 = do session cookies, else minutes
    var $that_class     = "AA_CT_Sql"; // name of data storage container
    var $gc_probability = 5;

    /** MyUrl function
     * rewriten to return URL of shtml page that includes this script instead to return self url of this script.
     *  If noquery parameter is true, session id is not added
     * @param $SliceID
     * @param $Encap
     * @param $noquery
     */
    function MyUrl($SliceID=0, $Encap=true, $noquery=false){  //SliceID is here just for compatibility with MyUrl function in extsess.php3
        global $scr_url;
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
            // You will need to fix suexec as well, if you use Apache and CGI PHP
            $PROTOCOL='https';
        } else {
            $PROTOCOL='http';
        }

        if( $scr_url ) {  // if included into php script
            $foo = $PROTOCOL. "://". $_SERVER['HTTP_HOST'].$scr_url;
        } elseif (isset($_SERVER['REDIRECT_DOCUMENT_URI'])) {  // CGI --enable-force-cgi-redirect
            $foo = $PROTOCOL. "://". $_SERVER['HTTP_HOST'].$_SERVER['REDIRECT_DOCUMENT_URI'];
        } elseif (isset($_SERVER['DOCUMENT_URI'])) {
            $foo = $PROTOCOL. "://". $_SERVER['HTTP_HOST'].$_SERVER['DOCUMENT_URI'];
        } elseif (isset($_SERVER['REQUEST_URI'])) {
         $url_parsed = parse_url($_SERVER['REQUEST_URI']);
         $foo = $PROTOCOL. "://". $_SERVER['HTTP_HOST'].$url_parsed['path'];
        } else {
            $foo = $PROTOCOL. "://". $_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_URL'];
        }

        switch ($this->mode) {
            case "get":
                if (!$noquery) {
                    $foo .= "?".urlencode($this->name)."=".$this->id;
                }
                break;
            default:
                break;
        }
        return $foo;
    }

    /** expand_getvars function
     *  adds variables passesd by QUERY_STRING_UNESCAPED to $_GET
     *  SSI patch - passes variables to SSIed script
     */
    function expand_getvars() {
        if (isset($_SERVER['REDIRECT_QUERY_STRING_UNESCAPED'])) {
            $varstring = $_SERVER['REDIRECT_QUERY_STRING_UNESCAPED'];
            // $REDIRECT_QUERY_STRING_UNESCAPED
            //  - necessary for cgi version compiled with --enable-force-cgi-redirect
        } elseif ( isset($_SERVER['QUERY_STRING_UNESCAPED']) ) {
          $varstring = $_SERVER['QUERY_STRING_UNESCAPED'];
        } else {
          $url_parsed = parse_url($_SERVER['REQUEST_URI']);
          $varstring = $url_parsed['query'];
        }

        $a = explode("&",$varstring);
        $i = 0;

        while ($i < count ($a)) {
            $b    = explode ('=', $a [$i]);
            $b[0] = DeBackslash2($b[0]);
            $b[1] = DeBackslash2($b[1]);
            $_GET[urldecode($b[0])]= urldecode($b[1]);
            $i++;
        }
        return $i;
    }
    /** get_id function
     * @param $id
     */
    function get_id() {

        $this->name = $this->cookiename=="" ? $this->classname : $this->cookiename;
        $newid = false;
        switch ($this->mode) {
        case "get":
            $this->expand_getvars(); // ssi patch
            $id = isset($_GET[$this->name]) ? $_GET[$this->name] : "";
            break;
        case "cookie":
            $id = isset($_COOKIE[$this->name]) ? $_COOKIE[$this->name] : "";
            break;
        default:
            die("This has not been coded yet.");
            break;
        }

        ## if not valid id, then reset it
        if ( (strlen($id) != 32) OR (strspn($id, "0123456789abcdefABCDEF") != strlen($id))) {
            $id = '';
        }

        if ("" == $id) {
            $newid = true;
            $id    = $this->that->ac_newid();
        }

        switch ($this->mode) {
        case "cookie":
            if ( $newid && ( 0 == $this->lifetime ) ) {
                SetCookie($this->name, $id, 0, "/");
            }
            if ( 0 < $this->lifetime ) {
                SetCookie($this->name, $id, time()+$this->lifetime*60, "/");
            }
            break;
        case "get":
            if ( isset($_SERVER['QUERY_STRING']) ) {
                $_SERVER['QUERY_STRING'] = ereg_replace(
                    "(^|&)".quotemeta(urlencode($this->name))."=".$id.'(&|$)',
                    "\\1", $_SERVER['QUERY_STRING']);
            }
            break;
        default:
            break;
        }

        $this->id = $id;
    }

    /** start function
     * @param $sid
     */
    function start() {
        $this->expand_getvars(); // ssi patch
        $name = $this->that_class;
        $this->that = new $name;
        $this->that->ac_start();

        $this->name = $this->cookiename=="" ? $this->classname : $this->cookiename;

        if (isset($this->fallback_mode) && ("get" == $this->fallback_mode ) && ("cookie" == $this->mode ) && (!isset($_COOKIE[$this->name]))) {

            if (isset($_GET[$this->name])) {
                $this->mode = $this->fallback_mode;
            } else {
                //header("Status: 302 Moved Temporarily");
                $this->get_id();
                $this->mode = $this->fallback_mode;
                    // You will need to fix suexec as well, if you use Apache and CGI PHP
                //$PROTOCOL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? 'https' : 'http';
                //header("Location: ". $PROTOCOL. "://". $_SERVER['HTTP_HOST'].$this->self_url());
                exit;
            }
        }

        $this->get_id();
        $this->thaw();
        $this->gc();   // Garbage collect, if necessary
    }
}

?>
