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
    return htmlspecialchars(get_url($url, $params), ENT_COMPAT | ENT_HTML401, 'ISO-8859-1');
}

/** makes url parameters to use with GET request from given parameters */
function HttpGetParameters($parameters) {
    $param_string = '';
    if (!is_array($parameters)) {
        $param_string = $parameters;
    } else {
        $delimiter = '';
        foreach ($parameters as $variable => $value) {
            // you can use it in three ways:
            //   1) $params = array('a=1', 'text=OK%20boy')
            //   2) $params = array('a' => 1, 'b' => 'OK boy')
            //   3) $params = array('als' => array('MY_ALIAS'=>x), 'b' => 'OK boy')
            if ( is_array($value) ) {
                foreach ($value as $inner_key => $inner_value) {
                    $param_string .= $delimiter. $variable. '['.rawurlencode($inner_key). ']='. rawurlencode($inner_value);
                    $delimiter     = '&';
                }
            } elseif ( is_numeric($variable)) {
                $param_string .= $delimiter. $value;
            } else {
                $param_string .= $delimiter. rawurlencode($variable). '='. rawurlencode($value);
            }
            $delimiter     = '&';
        }
    }
    return $param_string;
}

function StateUrl($url='') {
    return ((strpos($url, 'module_id=')===false) AND is_long_id(AA::$module_id)) ? get_url($url, array('module_id'=>AA::$module_id)) : $url;
}

function StateHidden() {
    return is_long_id(AA::$module_id) ? '<input type=hidden name=module_id value="'.AA::$module_id.'">' : '';
}


/** Appends any number of QUERY_STRING parameters (separated by &) to given URL,
 *  using apropriate ? or &. */
function get_url($url, $params='') {
    if (empty($params)) {
        return $url;
    }

    // be carefull - sometimes we pass url with aliases (like oedit.php3?oid=_#AA_ID___...)
    $param_string = HttpGetParameters($params);
    if (($pos = strpos($url, '?')) !== false)  { return substr_replace($url, "?$param_string&", $pos, 1); }
    if (($pos = strpos($url, '&')) !== false)  { return substr_replace($url, "&$param_string&", $pos, 1); }
    if (($pos = strrpos($url, '#')) !== false) { return substr_replace($url, "?$param_string#", $pos, 1); }
    return "$url?$param_string";
    // list($path, $fragment) = explode( '#', $url, 2 );
    // return $path . (strstr($path, '?') ? "&" : "?"). $param_string. ($fragment ? '#'.$fragment : '') ;
}


/** get_aa_url function
 * @param $href
 * @param $session
 */
function get_aa_url($href, $params='', $state=true) {
    $url = get_url(AA_INSTAL_PATH.$href, $params);
    return $state ? StateUrl($url) : $url;
}

/** get_admin_url function
 * @param $href
 * @param $session
 */
function get_admin_url($href, $params='', $state=true) {
    return get_aa_url("admin/$href", $params, $state);
}

/** get_help_url function
 *  returns url for $morehlp parameter in Frm* functions
 * @param $href
 * @param $anchor
 */
function get_help_url($href, $anchor) {
    return $href."#".$anchor;
}


/** go_url function
 * Move to another page (must be before any output from script)
 * @param $url
 * @param $add_param
 * @param $usejs
 */
function go_url($url, $add_param="", $usejs=false, $code=302) {
    global $sess;
    if (is_object($sess)) {
        page_close();
    }
    if ($add_param != "") {
        $url = get_url($url, $add_param);
    }
    if ( $usejs OR headers_sent() OR ($_SERVER['SERVER_PROTOCOL']=='INCLUDED')) { // SSI included
       echo '
        <script language="JavaScript" type="text/javascript"> <!--
            document.location = "'.rawurlencode($url).'";
          //-->
        </script>
       ';
    } else {
        header("Location: $url", true, $code);
    }
    exit;
}

require_once(AA_INC_PATH. "request.class.php3");
?>