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
    var $classname = "AA_SL_Session";

    var $cookiename     = "";                // defaults to classname
    var $magic          = "adwetdfgyr";      // ID seed
    var $mode           = "get";             // We propagate session IDs via cookie method
    var $fallback_mode  = "get";             // If cookie not possible, then via get method
    var $lifetime       = 0;                 // 0 = do session cookies, else minutes
    var $that_class     = "AA_CT_Sql";       // name of data storage container
    var $gc_probability = 5;
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

    var $cookiename     = "";                // defaults to classname
    var $magic          = "adwetdfgyr";      // ID seed
    var $mode           = "get";          // We propagate session IDs via cookie method
    // we still can't use cookie, since it is still not possible (or at least
    // recommended) to use two windows with the same session ID - we do not
    // store there only the session ID, but also slice_id, ... so it is possible
    // to mix the data.
    //    var $mode           = "cookie";          // We propagate session IDs via cookie method
    var $fallback_mode  = "get";             // If cookie not possible, then via get method
    var $lifetime       = 0;                 // 0 = do session cookies, else minutes
    var $that_class     = "AA_CT_Sql";       // name of data storage container
    var $gc_probability = 5;
    var $auto_init;                          // auto init
    var $allowcache     = "no";              // Control caching of session pages, if set to no (also the default), the page is not cached under HTTP/1.1 or HTTP/1.0; if set to public , the page is publically cached under HTTP/1.1 and HTTP/1.0; if set to private , the page is privately cached under HTTP/1.1 and not cached under HTTP/1.0
    var $allowcache_expire = 1;              // When caching is allowed, the pages can be cached for this many minutes.
    /** start function
     */
    function start() {
        $name = $this->that_class;
        $this->that = new $name;
        $this->that->ac_start();

        $this->name = $this->cookiename==""?$this->classname:$this->cookiename;

        if (isset($this->fallback_mode)
            && ("get" == $this->fallback_mode )
            && ("cookie" == $this->mode )
            && (!isset($_COOKIE[$this->name]))) {

            if (isset($_GET[$this->name])) {
                $this->mode = $this->fallback_mode;
            } else {
                header("HTTP/1.1 Status: 302 Moved Temporarily");
                $this->get_id();
                $this->mode = $this->fallback_mode;
                if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
                    // You will need to fix suexec as well, if you use Apache and CGI PHP
                    $PROTOCOL='https';
                } else {
                    $PROTOCOL='http';
                }
                header("Location: ". $PROTOCOL. "://". $_SERVER['HTTP_HOST'].$this->self_url());
                exit;
            }
        }

        // Allowing a limited amount of caching, as suggested by
        // Padraic Renaghan on phplib@shonline.de.
        // Note that in HTTP/1.1 the Cache-Control headers override the Expires
        // headers and HTTP/1.0 ignores headers it does not recognize (e.g,
        // Cache-Control). Mulitple Cache-Control directives are split into
        // mulitple headers to better support MSIE 4.x.
        switch ($this->allowcache) {

            case "public":
            $exp_gmt = gmdate("D, d M Y H:i:s", time() + $this->allowcache_expire * 60) . " GMT";
            $mod_gmt = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";
            header("Expires: " . $exp_gmt);
            header("Last-Modified: " . $mod_gmt);
            header("Cache-Control: public");
            header("Cache-Control: max-age=" . $this->allowcache_expire * 60);
            break;

            case "private":
            $mod_gmt = gmdate("D, d M Y H:i:s", getlastmod()) . " GMT";
            header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
            header("Last-Modified: " . $mod_gmt);
            header("Cache-Control: private");
            header("Cache-Control: max-age=" . $this->allowcache_expire * 60);
            break;

            default:
            header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
            header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
            header("Cache-Control: no-cache");
            header("Cache-Control: must-revalidate");
            header("Pragma: no-cache");
            break;
        }

        $this->get_id();
        $this->thaw();
        $this->gc();   // Garbage collect, if necessary
    }
}
?>
