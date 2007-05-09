<?php
/**
 * Function go_url used to move to another web page.
 * Formly this function was a part of util.php3 but in some pages
 * we don't want to include the whole util.
 *
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
 * @package   Utils
 * @version   $Id$
 * @author    Jakub Adamek, Econnect
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (c) 2002-3 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

/** con_url function
 *  Escapes url for usage on HTML page
 *  - it is better to use get_url() function and then escape the url before
 *  printing
 * @param $url
 * @param $params
 */
function con_url($url, $params) {
    return htmlentities(get_url($url, $params));
}

/** get_url function
 *  Appends any number of QUERY_STRING parameters (separated by &) to given URL,
 *  using apropriate ? or &.
 * @param $url
 * @param $params
 */
function get_url($url, $params) {
    list($path, $fragment) = explode( '#', $url, 2 );
    $param_string = '';
    if (!is_array($params)) {
        $param_string = $params;
    } else {
        $delimiter = '';
        foreach ($params as $variable => $value) {
            // you can use it in two ways:
            //   1) $params = array('a=1', 'text=OK%20boy')
            //   2) $params = array('a' => 1, 'b' => 'OK boy')
            if ( is_numeric($variable)) {
                $param_string .= $delimiter. $value;
            } else {
                $param_string .= $delimiter. rawurlencode($variable). '='. rawurlencode($value);
            }
            $delimiter     = '&';
        }
    }
    return $path . (strstr($path, '?') ? "&" : "?"). $param_string. ($fragment ? '#'.$fragment : '') ;
}

/** go_url function
 * Move to another page (must be before any output from script)
 * @param $url
 * @param $add_param
 * @param $usejs
 */
function go_url($url, $add_param="", $usejs=false) {
    global $sess, $rXn;
    if (is_object($sess)) {
        page_close();
    }
    if ($add_param != "") {
        $url = get_url( $url, rawurlencode($add_param));
    }
    // special parameter for Netscape to reload page
    $url = get_url($url,($rXn=="") ? "rXn=1" : "rXn=".++$rXn);
    if ( $usejs OR headers_sent() ) {
       echo '
        <script language="JavaScript" type="text/javascript"> <!--
            document.location = "'.$url.'";
          //-->
        </script>
       ';
    } else {
        header("HTTP/1.1 Status: 302 Moved Temporarily");
        header("Location: $url");
    }
    exit;
}

/** HttpPostRequest function
 *  POST data to the url (usin POST request and returns resulted data
 * @param $url
 * @param $data
 * @return array $result[]
 */
function HttpPostRequest($url, $data = array() ) {
    $request = parse_url($url);

    $host = $request['host'];
    $uri  = $request['path']. (empty($request['query']) ? '' : '?'.$request['query']);

    $reqbody = "";
    foreach($data as $key=>$val) {
        if (!empty($reqbody)) {
            $reqbody.= "&";
        }
        $reqbody.= $key."=".rawurlencode($val);
    }

    $contentlength = strlen($reqbody);
    $reqheader =  "POST $uri HTTP/1.1\r\n".
                  "Host: $host\n". "User-Agent: ActionApps\r\n".
                  "Content-Type: application/x-www-form-urlencoded\r\n".
                  "Content-Length: $contentlength\r\n\r\n".
                  "$reqbody\r\n";

    $socket = fsockopen($host, 80, $errno, $errstr);

    if (!$socket) {
        $result["errno"] = $errno;
        $result["errstr"] = $errstr;
        return $result;
    }

    fputs($socket, $reqheader);

    $responseHeader = '';
    $responseContent = '';

    do {
        $responseHeader.= fread($socket, 1);
    } while (!preg_match('/\\r\\n\\r\\n$/', $responseHeader));


    if (!strstr($responseHeader, "Transfer-Encoding: chunked")) {
        while (!feof($socket)) {
            $responseContent.= fgets($socket, 128);
        }
    } else {
        while ($chunk_length = hexdec(fgets($socket))) {
            $responseContentChunk = '';
            $read_length = 0;

            while ($read_length < $chunk_length) {
                $responseContentChunk .= fread($socket, $chunk_length - $read_length);
                $read_length = strlen($responseContentChunk);
            }

            $responseContent.= $responseContentChunk;

            fgets($socket);
        }
    }

    return array( 0=>$responseContent );
}

?>