<?php
/** linkcheck class - link validation for links module
 *
 *  functions:
 *
 *      check_url($url) - checks url (http and mailto), retunrs array with status
 *                        code, content-type, ...
 *      checking() - run checking of urls from table links_links, number of checked
 *                   links defined in LINKS_VALIDATION_CHECK
 *      add_check($arr, $valid_codes) - add new check values to string valid_codes
 *      remove_old($valid_codes) - removes old check values from valid_codes
 *      count_weight($valid_codes) - count valid_rank from valid_codes
 *
 *  arrays:
 *
 *      $http_error_codes - associates comment and weight to http error code
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
 * @package   Include
 * @version   $Id$
 * @author    Pavel Jisl <pavelji@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/


class linkcheck
{
    // http codes, error names and their weight
    var $http_error_codes = array (
    // first 3 are for checking domain from email
        'N/A' => array ("comment" => "Non HTTP", "weight" => "5"),
        'OK'  => array ("comment" => "Valid hostname", "weight" => "0"),
        'ERROR' => array ("comment" => "Invalid hostname", "weight" => "5"),
    // http error codes
        '999' => array ("comment" => "No response", "weight" => "5"),
        '100' => array ("comment" => "Continue", "weight" => "0"),
        '101' => array ("comment" => "Switching Protocols", "weight" => "0"),
        '200' => array ("comment" => "OK", "weight" => "0"),
        '201' => array ("comment" => "Created", "weight" => "0"),
        '202' => array ("comment" => "Accepted", "weight" => "0"),
        '203' => array ("comment" => "Non-Authoritative Information", "weight" => "0"),
        '204' => array ("comment" => "No Content", "weight" => "1"),
        '205' => array ("comment" => "Reset Content", "weight" => "0"),
        '206' => array ("comment" => "Partial Content", "weight" => "0"),
        '300' => array ("comment" => "Multiple Choices", "weight" => "2"),
        '301' => array ("comment" => "Moved Permanently", "weight" => "1"),
        '302' => array ("comment" => "Found", "weight" => "1"),
        '303' => array ("comment" => "See Other", "weight" => "2"),
        '304' => array ("comment" => "Not Modified", "weight" => "2"),
        '305' => array ("comment" => "Use Proxy", "weight" => "3"),
        '307' => array ("comment" => "Temporary Redirect", "weight" => "1"),
        '400' => array ("comment" => "Bad Request", "weight" => "5"),
        '401' => array ("comment" => "Unauthorized", "weight" => "4"),
        '402' => array ("comment" => "Payment Required", "weight" => "3"),
        '403' => array ("comment" => "Forbidden", "weight" => "5"),
        '404' => array ("comment" => "Not Found", "weight" => "5"),
        '405' => array ("comment" => "Method Not Allowed", "weight" => "5"),
        '406' => array ("comment" => "Not Acceptable", "weight" => "5"),
        '407' => array ("comment" => "Proxy Authentication Required", "weight" => "5"),
        '408' => array ("comment" => "Request Timeout", "weight" => "5"),
        '409' => array ("comment" => "Conflict", "weight" => "5"),
        '410' => array ("comment" => "Gone", "weight" => "5"),
        '411' => array ("comment" => "Length Required", "weight" => "5"),
        '412' => array ("comment" => "Precondition Failed", "weight" => "5"),
        '413' => array ("comment" => "Request Entity Too Large", "weight" => "5"),
        '414' => array ("comment" => "Request-URI Too Long", "weight" => "5"),
        '415' => array ("comment" => "Unsupported Media Type", "weight" => "5"),
        '416' => array ("comment" => "Requested Range Not Satisfiable", "weight" => "5"),
        '417' => array ("comment" => "Expectation Failed", "weight" => "5"),
        '500' => array ("comment" => "Internal Server Error", "weight" => "5"),
        '501' => array ("comment" => "Not Implemented", "weight" => "5"),
        '502' => array ("comment" => "Bad Gateway", "weight" => "5"),
        '503' => array ("comment" => "Service Unavailable", "weight" => "5"),
        '504' => array ("comment" => "Gateway Timeout", "weight" => "5"),
        '505' => array ("comment" => "HTTP Version Not Supported", "weight" => "5"));

    /** check_irl function
     *  check url - returns error codes, content type, error weight and comment
     * @param $url
     */
    function check_url($url) {
        $time = time();
        if (!eregi("^http://", $url)) {
            if (eregi("^mailto:", $url)) {
                $url = trim(eregi_replace("^mailto:(.+)", "\\1", $url));
                list($brugernavn, $host) = explode("@", $url);
                $dnsCheck = checkdnsrr($host,"MX");
                if ($dnsCheck) {
                    $return["code"] = "OK";
                } else {
                    $return["code"] = "ERROR";
                }
            } else {
                $return["code"] = "N/A";
            }
        } else {
            $urlArray = parse_url($url);
            if (!$urlArray["port"]) {
                $urlArray["port"] = "80";
            }
            if (!$urlArray["path"]) {
                $urlArray["path"] = "/";
            }
            $return["url"] = $url;
            $sock = @fsockopen($urlArray["host"], $urlArray["port"], $errnum, $errstr, 10);
            if (!$sock) {
                $return["code"] = "999";
            } else {
               $dump .= "HEAD ".$urlArray["path"]." HTTP/1.1\r\n";
               $dump .= "User-Agent: APC-AA Link Checker (http://www.ecn.cz/)\r\n";
               $dump .= "Host: ".$urlArray["host"]."\r\nConnection: close\r\n";
               $dump .= "Connection: close\r\n\r\n";
               fputs($sock, $dump);
               while (($str = fgets($sock, 1024)) && (($return["code"] == "") || ($return["contentType"] == ""))) {
                   $match = array();
                   if (preg_match("~^http/[0-9]+.[0-9]+ ([0-9]{3})~i", $str, $match)) {
                       $return["code"] = $match[1];
                   }
                   if (preg_match("~^Content-Type: (.*)~", $str, $match)) {
                       $return["contentType"] = $match[1];
                   }
                }
                fclose($sock);
                flush();
             }
        }
        $return["comment"]   = $this->http_error_codes[$return["code"]]["comment"];
        $return["weight"]    = $this->http_error_codes[$return["code"]]["weight"];
        $return["timestamp"] = $time;
        return $return;
    }

    /** checking function
     *  checking - runs check_url for links (count defined in LINKS_VALIDATION_COUNT)
     */
    function checking() {
        global $db;
        $db2 = new DB_AA;

        // select from links_links, ordered by validated (timestamp),
        // unchecked links have validated = 0
        $SQL = "SELECT id, url, valid_codes, valid_rank FROM links_links ORDER BY validated";
        $db->tquery($SQL);

        for ($i = 0; (($i < LINKS_VALIDATION_COUNT) && ($db->next_record())); ++$i) {

            $l_id  = $db->f('id');
            $l_url = $db->f('url');
            $l_vc  = $db->f('valid_codes');
            $l_vr  = $db->f('valid_rank');

            $val = $this->check_url($l_url); // check url
            $val["valid_codes"] = $this->add_check($val, $l_vc); // add this check into valid_codes
            $val["valid_rank"]  = $this->count_weight($val["valid_codes"]); // count rank for link

            if ($debug) {
                print_r($val);
            }

            // update values in db
            $SQL = "UPDATE links_links SET valid_codes='".$val["valid_codes"]."',
                                           valid_rank='" .$val["valid_rank"]. "',
                                           validated='"  .$val["timestamp"].  "'
                    WHERE id = '".$l_id."'";
            if ($debug) {
                echo "<pre> $SQL </pre>";
            }
            $db2->tquery($SQL);
        }
    }

    /** add_check function
     *  creates new valid_codes string (new is added as first)
     * @param $arr
     * @param $valid_codes
     */
    function add_check($arr, $valid_codes) {
        $element = $this->remove_old($valid_codes);
        $element = $arr["timestamp"]. ",". $arr["code"]. ":". $element;
        return $element;
    }

    /** remove_old function
     *  remove old checks - more than 10
     * @param $valid_codes
     */
    function remove_old($valid_codes) {
        $dummy = explode(":", $valid_codes);
        rsort($dummy);
        $valid_codes2 = "";
        if (count($dummy) > 9) {
            for ($i=0; $i < 10; ++$i) {
                $valid_codes2 .= $dummy[$i]. ($i==9 ? "" : ":");
            }
        } else {
            $valid_codes2 = $valid_codes;
        }
        return $valid_codes2;
    }

    /** count_weight function
     *  counts weight of link from it's http error codes
     * @param $valid_codes
     */
    function count_weight($valid_codes) {
        $dummy = explode(":", $valid_codes);
        rsort($dummy); // sort descend (newest is first)
        for ( $i=0, $ino=count($dummy); $i<$ino; ++$i) {
            list($arr[$i]["time"],$arr[$i]["code"]) = explode(",", $dummy[$i]); // split to array
            /*
            if ($i == 0) { // latest timestamp
                $latest = $arr[$i]["time"];
            }
            $arr[$i]["reltime"] = abs($arr[$i]["time"] - $latest);
            */
        }
        $count = count($arr);
        if ($count > 10) { // take only last 10
            $count = 10;
        }
        $num = 0;
        for ($i=0; $i<$count; ++$i) {
            /* exponential function - too fast near zero :(
            // $num =  exp((-1)*($arr[$i]["reltime"]/60/60/24))*$this->http_error_codes[$arr[$i]["code"]]["weight"];
            */
            // linear function
            $n1 = ($this->http_error_codes[$arr[$i]["code"]]["weight"]/($i+1));
            $num = $num + $n1;
        }
        // returned number - 1000 is the biggest number, with worst error codes, we want to return
        // 14.64... is the count of linear function with the worst error code weights
        $ret = round($num * (1000/14.644841269841));

        return $ret;
    }

} // end - class linkcheck

?>
