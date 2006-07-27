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

class DB_AA extends DB_Sql {
    var $Host     = DB_HOST;
    var $Database = DB_NAME;
    var $User     = DB_USER;
    var $Password = DB_PASSWORD;

    function tquery($SQL) {
        return ($GLOBALS['debug'] ? $this->dquery($SQL) : $this->query($SQL));
    }

    function dquery($SQL) {
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

    function query_nohalt($SQL) {
        $store_halt          = $this->Halt_On_Error;
        $this->Halt_On_Error = 'no';
        $retval              = $this->query($SQL);
        $this->Halt_On_Error = $store_halt;
        return $retval;
    }

    function halt($msg) {
        if ($this->Halt_On_Error == "no") {
            return;
        }
        printf("</td></table><b>Database error:</b> %s<br>\n", $msg);
        printf("<b>Error Number (description)</b>: %s (%s)<br>\n", $this->Errno, $this->Error);
        echo("Please contact ". ERROR_REPORTING_EMAIL ." and report the ");
        printf("exact error message.<br>\n");
        if ($this->Halt_On_Error == "yes") {
            die("Session halted.");
        }
    }
}

class AA_CT_Sql extends CT_Sql {	// Container Type for Session is SQL DB
    var $database_class = "DB_AA";          // Which database to connect...
    var $database_table = "active_sessions"; // and find our session data in this table.
}



// skips terminating backslashes
function DeBackslash2($txt) {
    return str_replace('\\', "", $txt);        // better for two places
}


class AA_SL_Session extends Session {
    var $classname = "AA_SL_Session";

    var $cookiename     = "";                // defaults to classname
    var $magic          = "adwetdfgyr";      // ID seed
    var $mode           = "cookie";          // We propagate session IDs via cookie method
    var $fallback_mode  = "get";             // If cookie not possible, then via get method
    var $lifetime       = 0;                 // 0 = do session cookies, else minutes
    var $that_class     = "AA_CT_Sql"; // name of data storage container
    var $gc_probability = 5;

    //rewriten to return URL of shtml page that includes this script instead to return self url of this script. If noquery parameter is true, session id is not added
    function MyUrl($SliceID=0, $Encap=true, $noquery=false){  //SliceID is here just for compatibility with MyUrl function in extsess.php3
        global $HTTP_HOST, $HTTPS, $DOCUMENT_URI, $REQUEST_URI, $REDIRECT_DOCUMENT_URI, $SCRIPT_URL, $scr_url;
        if (isset($HTTPS) && $HTTPS == 'on') {
            // You will need to fix suexec as well, if you use Apache and CGI PHP
            $PROTOCOL='https';
        } else {
            $PROTOCOL='http';
        }

        if( $scr_url ) {  // if included into php script
            $foo = $PROTOCOL. "://". $HTTP_HOST.$scr_url;
        } elseif (isset($REDIRECT_DOCUMENT_URI)) {  // CGI --enable-force-cgi-redirect
            $foo = $PROTOCOL. "://". $HTTP_HOST.$REDIRECT_DOCUMENT_URI;
        } elseif (isset($DOCUMENT_URI)) {
            $foo = $PROTOCOL. "://". $HTTP_HOST.$DOCUMENT_URI;
        } elseif (isset($REQUEST_URI)) {
         $url_parsed = parse_url($REQUEST_URI);
         $foo = $PROTOCOL. "://". $HTTP_HOST.$url_parsed['path'];
        } else {
            $foo = $PROTOCOL. "://". $HTTP_HOST.$SCRIPT_URL;
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

    // adds variables passesd by QUERY_STRING_UNESCAPED to HTTP_GET_VARS
    // SSI patch - passes variables to SSIed script
    function expand_getvars() {
        global $QUERY_STRING_UNESCAPED, $REDIRECT_QUERY_STRING_UNESCAPED, $HTTP_GET_VARS, $REQUEST_URI;
        if (isset($REDIRECT_QUERY_STRING_UNESCAPED)) {
            $varstring = $REDIRECT_QUERY_STRING_UNESCAPED;
            // $REDIRECT_QUERY_STRING_UNESCAPED
            //  - necessary for cgi version compiled with --enable-force-cgi-redirect
        } elseif ( isset($QUERY_STRING_UNESCAPED) ) {
          $varstring = $QUERY_STRING_UNESCAPED;
        } else {
          $url_parsed = parse_url($REQUEST_URI);
          $varstring = $url_parsed['query'];
        }

        $a = explode("&",$varstring);
        $i = 0;

        while ($i < count ($a)) {
            $b = explode ('=', $a [$i]);
            $b[0] = DeBackslash2($b[0]);
            $b[1] = DeBackslash2($b[1]);
            $HTTP_GET_VARS[urldecode ($b [0])]= urldecode ($b [1]);
            $i++;
        }
        return $i;
    }

    function get_id($id = "") {
        global $HTTP_COOKIE_VARS, $HTTP_GET_VARS, $QUERY_STRING;
        $newid=true;

        $this->name = $this->cookiename==""?$this->classname:$this->cookiename;

        if ("" == $id) {
            $newid=false;
            switch ($this->mode) {
                case "get":
                    $this->expand_getvars(); // ssi patch
                    $id = isset($HTTP_GET_VARS[$this->name]) ? $HTTP_GET_VARS[$this->name] : "";
                    break;
                case "cookie":
                    $id = isset($HTTP_COOKIE_VARS[$this->name]) ? $HTTP_COOKIE_VARS[$this->name] : "";
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
                if ( isset($QUERY_STRING) ) {
                    $QUERY_STRING = ereg_replace(
                    "(^|&)".quotemeta(urlencode($this->name))."=".$id."(&|$)",
                    "\\1", $QUERY_STRING);
                }
                break;
            default:
                break;
        }

        $this->id = $id;
    }

    function start($sid = "") {
        global $HTTP_COOKIE_VARS, $HTTP_GET_VARS, $HTTP_HOST, $HTTPS;
        $this->expand_getvars(); // ssi patch
        $name = $this->that_class;
        $this->that = new $name;
        $this->that->ac_start();

        $this->name = $this->cookiename==""?$this->classname:$this->cookiename;

        if (isset($this->fallback_mode)
            && ("get" == $this->fallback_mode )
            && ("cookie" == $this->mode )
            && (!isset($HTTP_COOKIE_VARS[$this->name]))) {

            if (isset($HTTP_GET_VARS[$this->name])) {
                $this->mode = $this->fallback_mode;
            } else {
                //header("Status: 302 Moved Temporarily");
                $this->get_id($sid);
                $this->mode = $this->fallback_mode;
                if (isset($HTTPS) && $HTTPS == 'on') {
                    // You will need to fix suexec as well, if you use Apache and CGI PHP
                    $PROTOCOL='https';
                } else {
                    $PROTOCOL='http';
                }
                //header("Location: ". $PROTOCOL. "://". $HTTP_HOST.$this->self_url());
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
