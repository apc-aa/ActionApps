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

class DB_AA extends DB_Sql {
    var $Host      = DB_HOST;
    var $Database  = DB_NAME;
    var $User      = DB_USER;
    var $Password  = DB_PASSWORD;
    var $Auto_Free = 'yes';
    /** tquery function
     * @param $SQL
     */
    function tquery($SQL) {
        return ($GLOBALS['debug'] ? $this->dquery($SQL) : $this->query($SQL));
    }
    /** dquery function
     * @param $SQL
     */
    function dquery($SQL) {
        global $debugtimes,$debugtimestart;
        if ($debugtimes) {
            if (! $debugtimestart) {
                $debugtimestart = get_microtime();
            }
            echo "\n<br>Time: ".(get_microtime() - $debugtimestart)."\n";
        }
        echo "<br>".htmlentities($SQL);

        $SelectQuery = (strpos( " ".$SQL, "SELECT") == 1);
        // only SELECT queries can be explained
        if ($SelectQuery)  {
            $this->query("explain ".$SQL);

            echo "<table><tr><td><b>table</b></td> <td><b>type</b></td><td><b>possible_keys</b></td><td><b>key</b></td><td><b>key_len</b></td><td><b>ref</b></td><td><b>rows</b></td><td><b>Extra</b></td></tr>";
            while ($this->next_record()) {
                printf( "<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>",
                         $this->f('table'), $this->f('type'), $this->f('possible_keys'), $this->f('key'), $this->f('key_len'), $this->f('ref'), $this->f('rows'), $this->f('Extra'));
            }
            echo "</table>";
        }

        list($usec, $sec) = explode(" ",microtime());
        $starttime = ((float)$usec + (float)$sec);

        $retval = $this->query($SQL);

        list($usec, $sec) = explode(" ",microtime());
        $endtime = ((float)$usec + (float)$sec);
        echo "<br>Query duration: ". ($endtime - $starttime);
        echo $SelectQuery ? "<br>Rows returned: ".$this->num_rows() :
                            "<br>Affected rows: ".$this->affected_rows();
        return $retval;
    }

    /** query_nohalt function
     * @param $SQL
     */
    function query_nohalt($SQL) {
        $store_halt          = $this->Halt_On_Error;
        $this->Halt_On_Error = 'no';
        $retval              = $this->tquery($SQL);
        $this->Halt_On_Error = $store_halt;
        return $retval;
    }

    /** halt function
     * @param $msg
     */
    function halt($msg) {
        if ($this->Halt_On_Error == "no") {
            return;
        }

        // if you want to display special error page, then define DB_ERROR_PAGE
        // in config.php3 file. You can use following variables on that page
        // (in case you will use php page):
        // $_POST['Err'], $_POST['ErrMsg'] and $_POST['Msg'] variables
        // --- Disabled -- AA_Http::go() for POST works in the way, that the
        // page content is grabbed into variable and printed on current page.
        // It works pretty well, but if you link the external css on tahat page,
        // then it is not found, which is unexpected behavior. So, you can't use
        // the variables on that page. Honza, 2007-12-05
        if (defined('DB_ERROR_PAGE') AND ($this->Halt_On_Error == "yes")) {
            ob_end_clean();
            // AA_Http::go(DB_ERROR_PAGE, array('Err'=>$this->Errno, 'ErrMsg'=>$this->Error, 'Msg'=>$msg), 'POST', false);
            // sending variables disabled - see the comment above
            AA_Http::go(DB_ERROR_PAGE, null, 'GET', false);
            exit;
        }

        // If you do not want (for security reasons) display messages like:
        // "Database error: mysql_pconnect(mysqldbserver, aadbuser, $Password) failed."
        // then just define DB_ERROR_PAGE constant in your config.php3 file
        echo "\n<br><b>Database error:</b> $msg";
        echo "\n<br><b>Error Number:</b>: ". $this->Errno;
        echo "\n<br><b>Error Description:</b>: ". $this->Error;
        echo "\n<br>Please contact ". ERROR_REPORTING_EMAIL ." and report the exact error message.<br>\n";
        if ($this->Halt_On_Error == "yes") {
            die("Session halted.");
        }
    }
}

class AA_CT_Sql extends CT_Sql {	         // Container Type for Session is SQL DB
    var $database_class = "DB_AA";           // Which database to connect...
    var $database_table = "active_sessions"; // and find our session data in this table.
}

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
     * @param $sid
     */
    function start($sid = "") {
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
                $this->get_id($sid);
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

        $this->get_id($sid);
        $this->thaw();

        // Garbage collect, if necessary
        srand(time());
        if ((rand()%100) < $this->gc_probability) {
            $this->gc();
        }
    }
}

/*
class Example_CT_Shm extends CT_Shm {
    var $max_sessions   = 500;               // number of maximum sessions
    var $shm_key        = 0x123754;          // unique shm identifier
    var $shm_size       = 64000;             // size of segment
}

class Example_CT_Ldap extends CT_Ldap {
    var $ldap_host = "localhost";
    var $ldap_port = 389;
    var $basedn    = "dc=your-domain, dc=com";
    var $rootdn    = "cn=root, dc=your-domain, dc=com";
    var $rootpw    = "secret";
    var $objclass  = "phplibdata";
}

class Example_CT_Dbm extends CT_Dbm {
    var $dbm_file  = "must_exist.dbm";
}
*/

?>
