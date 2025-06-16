<?php

namespace AA\Util;

use AA\IO\DB\DB_AA;

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
 *      $self::$HTTP_ERROR_CODES - associates comment and weight to http error code
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
 * @package   Include
 * @version   $Id$
 * @author    Pavel Jisl <pavelji@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */
class Linkcheck {

    // http codes, error names and their weight
    private const HTTP_ERROR_CODES = [
        // first 3 are for checking domain from email
        'N/A'   => [ "comment" => "Non HTTP",                         "weight" => 5 ],
        'OK'    => [ "comment" => "Valid hostname",                   "weight" => 0 ],
        'ERROR' => [ "comment" => "Invalid hostname",                 "weight" => 5 ],
        // http error codes
        '999'   => [ "comment" => "No response",                      "weight" => 5 ],
        '100'   => [ "comment" => "Continue",                         "weight" => 0 ],
        '101'   => [ "comment" => "Switching Protocols",              "weight" => 0 ],
        '102'   => [ "comment" => "Processing",                       "weight" => 0 ], // WebDAV RFC2518
        '103'   => [ "comment" => "Early Hints",                      "weight" => 0 ], // RFC8297
        // Successful 2xx
        '200'   => [ "comment" => "OK",                               "weight" => 0 ],
        '201'   => [ "comment" => "Created",                          "weight" => 0 ],
        '202'   => [ "comment" => "Accepted",                         "weight" => 0 ],
        '203'   => [ "comment" => "Non-Authoritative Information",    "weight" => 0 ],
        '204'   => [ "comment" => "No Content",                       "weight" => 1 ],
        '205'   => [ "comment" => "Reset Content",                    "weight" => 0 ],
        '206'   => [ "comment" => "Partial Content",                  "weight" => 0 ],
        '207'   => [ "comment" => "Multi-Status",                     "weight" => 2 ], // WebDAV RFC4918
        '208'   => [ "comment" => "Already Reported",                 "weight" => 2 ], // WebDAV RFC5842
        '226'   => [ "comment" => "IM Used",                          "weight" => 1 ], // RFC3229
        // Redirection 3xx
        '300'   => [ "comment" => "Multiple Choices",                 "weight" => 2 ],
        '301'   => [ "comment" => "Moved Permanently",                "weight" => 1 ],
        '302'   => [ "comment" => "Found",                            "weight" => 1 ],
        '303'   => [ "comment" => "See Other",                        "weight" => 2 ],
        '304'   => [ "comment" => "Not Modified",                     "weight" => 2 ],
        '305'   => [ "comment" => "Use Proxy",                        "weight" => 3 ],
        '306'   => [ "comment" => "Unused",                           "weight" => 3 ],
        '307'   => [ "comment" => "Temporary Redirect",               "weight" => 1 ],
        '308'   => [ "comment" => "Permanent Redirect",               "weight" => 1 ], // RFC7538
        // Client Error 4xx
        '400'   => [ "comment" => "Bad Request",                      "weight" => 5 ],
        '401'   => [ "comment" => "Unauthorized",                     "weight" => 4 ],
        '402'   => [ "comment" => "Payment Required",                 "weight" => 3 ],
        '403'   => [ "comment" => "Forbidden",                        "weight" => 5 ],
        '404'   => [ "comment" => "Not Found",                        "weight" => 5 ],
        '405'   => [ "comment" => "Method Not Allowed",               "weight" => 5 ],
        '406'   => [ "comment" => "Not Acceptable",                   "weight" => 5 ],
        '407'   => [ "comment" => "Proxy Authentication Required",    "weight" => 5 ],
        '408'   => [ "comment" => "Request Timeout",                  "weight" => 5 ],
        '409'   => [ "comment" => "Conflict",                         "weight" => 5 ],
        '410'   => [ "comment" => "Gone",                             "weight" => 5 ],
        '411'   => [ "comment" => "Length Required",                  "weight" => 5 ],
        '412'   => [ "comment" => "Precondition Failed",              "weight" => 5 ],
        '413'   => [ "comment" => "Content Too Large",                "weight" => 5 ], // RFC9110
        '414'   => [ "comment" => "URI Too Long",                     "weight" => 5 ], // RFC9110
        '415'   => [ "comment" => "Unsupported Media Type",           "weight" => 5 ],
        '416'   => [ "comment" => "Range Not Satisfiable",            "weight" => 5 ], // RFC9110
        '417'   => [ "comment" => "Expectation Failed",               "weight" => 5 ],
        '418'   => [ "comment" => "I'm a teapot",                     "weight" => 3 ], // RFC2324
        '421'   => [ "comment" => "Misdirected Request",              "weight" => 4 ], // RFC7540
        '422'   => [ "comment" => "Unprocessable Content",            "weight" => 4 ], // RFC9110
        '423'   => [ "comment" => "Locked",                           "weight" => 4 ], // WebDAV
        '424'   => [ "comment" => "Failed Dependency",                "weight" => 4 ], // WebDAV
        '425'   => [ "comment" => "Too Early",                        "weight" => 3 ], // RFC8470
        '426'   => [ "comment" => "Upgrade Required",                 "weight" => 3 ],
        '428'   => [ "comment" => "Precondition Required",            "weight" => 4 ], // RFC6585
        '429'   => [ "comment" => "Too Many Requests",                "weight" => 4 ], // RFC6585
        '431'   => [ "comment" => "Request Header Fields Too Large",  "weight" => 4 ], // RFC6585
        '451'   => [ "comment" => "Unavailable For Legal Reasons",    "weight" => 5 ], // RFC7725
        // Server Error 5xx
        '500'   => [ "comment" => "Internal Server Error",            "weight" => 5 ],
        '501'   => [ "comment" => "Not Implemented",                  "weight" => 5 ],
        '502'   => [ "comment" => "Bad Gateway",                      "weight" => 5 ],
        '503'   => [ "comment" => "Service Unavailable",              "weight" => 5 ],
        '504'   => [ "comment" => "Gateway Timeout",                  "weight" => 5 ],
        '505'   => [ "comment" => "HTTP Version Not Supported",       "weight" => 5 ],
        '506'   => [ "comment" => "Variant Also Negotiates",          "weight" => 5 ], // RFC2295
        '507'   => [ "comment" => "Insufficient Storage",             "weight" => 5 ], // WebDAV
        '508'   => [ "comment" => "Loop Detected",                    "weight" => 5 ], // WebDAV
        '510'   => [ "comment" => "Not Extended",                     "weight" => 5 ], // RFC2774
        '511'   => [ "comment" => "Network Authentication Required",  "weight" => 5 ], // RFC6585
    ];
    /** check url - returns error codes, content type, error weight and comment
     * @param $url
     * @return array
     */
    public function check_url(string $url): array
    {
        $result = [
            'code'         => null,
            'comment'      => null,
            'weight'       => null,
            'timestamp'    => time(),
            'content_type' => null,
            'mime'         => null,
            'charset'      => null,
            'redir'        => null,
            'redir_chain'  => [],
            'http_codes'   => [],
            'error'        => null,
        ];

        // mailto nebo ne-HTTP link
        $url_lower = strtolower(substr($url, 0, 10));
        if (!str_starts_with($url_lower, 'http://') && !str_starts_with($url_lower, 'https://')) {
            if (str_starts_with($url_lower, 'mailto:')) {
                $email = trim(preg_replace("/^mailto:(.+)/i", "\\1", $url));
                [$username, $host] = explode("@", $email);
                $dnsCheck = checkdnsrr($host, "MX");
                $code = $dnsCheck ? "OK" : "ERROR";
                $result['code'] = $code;
                $result['comment'] = self::HTTP_ERROR_CODES[$code]['comment'] ?? 'Unknown';
                $result['weight']  = self::HTTP_ERROR_CODES[$code]['weight'] ?? 5;
            } else {
                $result['code'] = "N/A";
            }
            $result['comment'] = self::HTTP_ERROR_CODES['N/A']['comment'];
            $result['weight']  = self::HTTP_ERROR_CODES['N/A']['weight'];
            return $result;
        }

        // --- cURL inicializace ---
        $ch = curl_init();

        // get chain redirects using callback (PHP 7.1+)
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_NOBODY         => true, // HEAD request (do not fetch body)
            CURLOPT_HEADER         => true, // we want headers
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_USERAGENT      => 'APC-AA Link Checker (https://actionapps.org/)',
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        // Get redirect chain
        $chain = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$chain) {
            if (preg_match('~^Location:\s*(.+)~i', $header, $m)) {
                $chain[] = trim($m[1]);
            }
            return strlen($header);
        });

        $response = curl_exec($ch);

        if ($response === false) {
            $result['code'] = "999";
            $result['comment'] = 'No response (' . curl_error($ch) . ')';
            $result['weight'] = 5;
            $result['error'] = curl_error($ch);
            curl_close($ch);
            return $result;
        }

        // Get info about redirects and returned codes
        $info        = curl_getinfo($ch);
        $http_code   = $info['http_code'];
        $finalUrl    = $info['url'];
        $contentType = $info['content_type'] ?? null;

        curl_close($ch);
        
        // get all codes from Location chain from response headers
        $headers = explode("\r\n\r\n", trim($response));
        $http_codes = [];
        foreach ($headers as $headerBlock) {
            if (preg_match('~HTTP/\d+\.\d+\s+(\d{3})~', $headerBlock, $matches)) {
                $http_codes[] = $matches[1];
            }
        }

        $result['content_type'] = $contentType;

        [$type, $params]        = explode(';', $contentType . ';', 2);
        $result['mime']         = trim($type);
        $result['charset']      = preg_match('/charset\s*=\s*"?([^\s;"]+)"?/i', $params, $m) ? strtolower($m[1]) : '';

        if ($chain) {  // redirects
            $result['redir_chain'] = $chain;
            $result['http_codes'] = $http_codes;
            $result['redir']  = $finalUrl;
            $result['code'] = $http_codes ? (string) $http_codes[0] : (string) $http_code;
            $result['comment'] = self::HTTP_ERROR_CODES[$result['code']]['comment'] ?? 'Unknown';
            $result['weight']  = self::HTTP_ERROR_CODES[$result['code']]['weight'] ?? 5;

            // modify weight by the status of the final url

            // last value of http_codes is the last code
            $last_code = end($http_codes);
            $result['weight'] = min( 5, max( $result['weight'], self::HTTP_ERROR_CODES[$last_code]['weight']));  // weight not 0 but taken from last code
            $result['comment'] .= " - Redirected [$last_code]";
            return $result;
        } 

        $result['code'] = (string) $http_code;
        $result['comment'] = self::HTTP_ERROR_CODES[$http_code]['comment'] ?? 'Unknown';
        $result['weight']  = self::HTTP_ERROR_CODES[$http_code]['weight'] ?? 5;
        return $result;
    }


    /** checking function
     *  checking - runs check_url for links (count defined in LINKS_VALIDATION_COUNT)
     */
    function checking(): void
    {
        $db = getDB();

        // select from links_links, ordered by validated (timestamp),
        // unchecked links have validated = 0
        $links = DB_AA::select([], 'SELECT id, url, valid_codes, valid_rank FROM links_links ORDER BY validated');
        $max   = min(LINKS_VALIDATION_COUNT, count($links));

        for ($i = 0; $i < $max; ++$i) {

            $l_id  = $links['id'];
            $l_url = (string)$links['url'];
            $l_vc  = $links['valid_codes'];
            $l_vr  = $links['valid_rank'];

            $val                = $this->check_url($l_url); // check url
            $val["valid_codes"] = $this->add_check($val, $l_vc); // add this check into valid_codes
            $val["valid_rank"]  = $this->count_weight($val["valid_codes"]); // count rank for link

            // update values in db
            $SQL = "UPDATE links_links SET valid_codes='" . $val["valid_codes"] . "',
                                           valid_rank='" . $val["valid_rank"] . "',
                                           validated='" . $val["timestamp"] . "'
                    WHERE id = '" . $l_id . "'";
            $db->query($SQL);
        }
        freeDB($db);
    }

    /** add_check function
     *  creates new valid_codes string (new is added as first)
     * @param $arr
     * @param $valid_codes
     * @return string
     */
    function add_check($arr, $valid_codes): string
    {
        return $arr["timestamp"] . "," .$arr["code"] . ":" .$this->remove_old($valid_codes);
    }

    /** remove_old function
     *  remove old checks - more than 10
     * @param $valid_codes
     * @return string
     */
    function remove_old($valid_codes): string
    {
        $dummy = explode(":", $valid_codes);
        rsort($dummy);
        $valid_codes2 = "";
        if (count($dummy) > 9) {
            for ($i = 0; $i < 10; ++$i) {
                $valid_codes2 .= $dummy[$i] . ($i === 9 ? "" : ":");
            }
        } else {
            $valid_codes2 = $valid_codes;
        }
        return $valid_codes2;
    }

    /** count_weight function
     *  counts weight of link from it's http error codes
     * @param $valid_codes
     * @return float
     */
    function count_weight($valid_codes): float {
        $dummy = explode(":", $valid_codes);
        rsort($dummy); // sort descend (newest is first)
        foreach ($dummy as $i => $singleDummy) {
            [$arr[$i]["time"], $arr[$i]["code"]] = explode(",", $singleDummy);
            // split to array
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
        for ($i = 0; $i < $count; ++$i) {
            /* exponential function - too fast near zero :(
            // $num =  exp((-1)*($arr[$i]["reltime"]/60/60/24))*self::HTTP_ERROR_CODES[$arr[$i]["code"]]["weight"];
            */
            // linear function
            $n1  = (self::HTTP_ERROR_CODES[$arr[$i]["code"]]["weight"] / ($i + 1));
            $num += $n1;
        }
        // returned number - 1000 is the biggest number, with worst error codes, we want to return
        // 14.64... is the count of linear function with the worst error code weights
        return round($num * (1000 / 14.644841269841));
    }

} // end - class AA_Linkcheck


