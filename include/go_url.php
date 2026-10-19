<?php

/**
 * Function go_url used to move to another web page.
 * Formly this function was a part of util.php but in some pages
 * we don't want to include the whole util.
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/


/** Escapes url for usage on HTML page
 *  - it is better to use get_url() function and then escape the url before printing
 * @param $url
 * @param  array|string $params
 * @return string
 */
function con_url($url, $params = [], $state = false): string
{
    return htmlspecialchars(get_url($url, $params, $state), ENT_QUOTES | ENT_HTML5, 'ISO-8859-1');
}

/** makes url parameters to use with GET request from given parameters
 * @param array|string $parameters
 * @return string
 */
function HttpGetParameters($parameters): string
{
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
            if (is_array($value)) {
                foreach ($value as $inner_key => $inner_value) {
                    $param_string .= $delimiter . $variable . '[' . rawurlencode($inner_key) . ']=' . rawurlencode($inner_value);
                    $delimiter     = '&';
                }
            } elseif (ctype_digit((string) $variable)) {
                $param_string .= $delimiter . $value;
            } else {
                $param_string .= $delimiter . rawurlencode($variable) . '=' . rawurlencode((string) $value);
            }
            $delimiter     = '&';
        }
    }
    return $param_string;
}

/**
 * @param string $url
 * @return string
 */
function StateUrl($url = ''): string
{
    return (!str_contains($url, 'module_id=') && is_long_id(AA::$module_id)) ? get_url($url, ['module_id' => AA::$module_id]) : $url;
}

/**
 * @return string
 */
function StateHidden(): string
{
    return is_long_id(AA::$module_id) ? '<input type=hidden name=module_id value="' . AA::$module_id . '">' : '';
}


/** Appends any number of QUERY_STRING parameters (separated by &) to given URL, using appropriate ? or &.
 * @param  string  $url
 * @param  array   $params
 * @param  bool    $state
 * @return string
 */
function get_url($url, $params = [], $state = false): string
{
    if (empty($params)) {
        return $state ? StateUrl($url) : $url;
    }

    // be careful - sometimes we pass url with aliases (like oedit.php?oid=_#AA_ID___...)
    $param_string = HttpGetParameters($params);
    if (($pos = strpos($url, '?')) !== false) {
        return substr_replace($url, "?$param_string&", $pos, 1);
    }
    // an "&" without "?" is a part of the path (old links like /cz-35&wa=x/page), not a place for parameters
    if (($pos = strrpos($url, '#')) !== false) {
        return substr_replace($url, "?$param_string#", $pos, 1);
    }

    return $state ? StateUrl("$url?$param_string") : "$url?$param_string";
    // [$path, $fragment] = explode( '#', $url, 2 );
    // return $path . (strstr($path, '?') ? "&" : "?"). $param_string. ($fragment ? '#'.$fragment : '') ;
}


/** get_aa_url function
 * @param              $href
 * @param array|string $params
 * @param bool         $state
 * @return string
 */
function get_aa_url($href, $params = [], $state = true): string
{
    return get_url(AA_INSTAL_PATH . $href, $params, $state);
}

/** get_admin_url function
 * @param              $href
 * @param array|string $params
 * @param bool         $state
 * @return string
 */
function get_admin_url($href = '', $params = '', $state = true): string
{
    $href = $href ? AA_INSTAL_PATH . "admin/$href" : $_SERVER['PHP_SELF'];
    return get_url($href, $params, $state);
}

/** get_help_url function
 *  returns url for $morehlp parameter in Frm* functions
 * @param $href
 * @param $anchor
 * @return string
 */
function get_help_url($href, $anchor): string
{
    return $href . "#" . $anchor;
}


/** The script by which go_url() moves to $url once the headers are sent. The URL may come from the
 *  request (return_url), so it is a JS string, and a URL of another scheme than http(s) moves nowhere:
 *  window.location would run javascript: or data:, which a Location header never does.
 */
function GoUrlScript(string $url): string
{
    // the browser drops tabs, newlines and leading controls before it reads the scheme
    $scheme = preg_match('/^([a-z][a-z0-9+.-]*):/i', (string) preg_replace('/[\x00-\x20]+/', '', $url), $m) ? strtolower($m[1]) : '';
    if (($scheme !== '') && !in_array($scheme, ['http', 'https'], true)) {
        return '';
    }
    return "<script>window.location = '" . escape4js($url) . "';</script>";
}

/** Move to another page (must be before any output from script)
 * @param $url
 * @param $add_param array|string
 * @param $usejs
 */
function go_url($url, $add_param = "", $usejs = false, $code = 302): never
{
    global $sess;
    if (is_object($sess)) {
        Session::page_close();
    }
    if ($add_param != "") {
        $url = get_url($url, $add_param);
    }

    if ($usejs || headers_sent() || $_SERVER['SERVER_PROTOCOL'] === 'INCLUDED') { // SSI included  // INCLUDED does not work 2024 HM
        echo GoUrlScript((string) $url);
    } else {
        header("Location: $url", true, $code);
    }
    exit;
}
