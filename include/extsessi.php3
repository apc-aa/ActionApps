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

}

?>
