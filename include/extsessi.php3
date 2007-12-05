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
    var $Host     = DB_HOST;
    var $Database = DB_NAME;
    var $User     = DB_USER;
    var $Password = DB_PASSWORD;
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
    function get_id($id = "") {
        $newid=true;

        $this->name = $this->cookiename==""?$this->classname:$this->cookiename;

        if ("" == $id) {
            $newid=false;
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
        }

        if ("" == $id) {
            $newid=true;
            $id = $this->that->ac_newid(md5(uniqid($this->magic)), $this->name);
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
                    "(^|&)".quotemeta(urlencode($this->name))."=".$id."(&|$)",
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
    function start($sid = "") {
        $this->expand_getvars(); // ssi patch
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
                //header("Status: 302 Moved Temporarily");
                $this->get_id($sid);
                $this->mode = $this->fallback_mode;
                if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
                    // You will need to fix suexec as well, if you use Apache and CGI PHP
                    $PROTOCOL='https';
                } else {
                    $PROTOCOL='http';
                }
                //header("Location: ". $PROTOCOL. "://". $_SERVER['HTTP_HOST'].$this->self_url());
                exit;
            }
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

?>
