<?php

/**
 * File contains definition of inputform class - used for displaying input form
 * for item add/edit and other form utility functions
 *
 * Should be included to other scripts (as /admin/itemedit.php)
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Cache;

use AA;
use AA\Util\Hitcounter;
use AA\Util\Preconnect;
use zids;

class Cacheentry
{
    protected $c = '';      // content
    protected $h = []; // headers array
    protected $i = '';      // item id for page hit
    protected $p = '';      // Cacheability - private/public/no-cache - private used for pages, where user is logged in
    protected $t = 0;       // timestamp

    public function __construct($content, array $headers = [], $item_id = '', $cacheability = 'public')
    {
        $this->c = $content;
        $this->h = $headers;
        if (($preconnect = Preconnect::header($headers, (string) $content)) !== '') {
            $this->h['preconnect'] = $preconnect;
        }
        $this->i = $item_id;
        $this->p = $cacheability;
        // the request start: Apache dates the response by it and Last-Modified must not be later
        $this->t = (int) ($_SERVER['REQUEST_TIME'] ?? time());
    }

    /** send headers, print output and count hit of the page item and of $hits */
    public function processPage(bool $cached = false, zids ...$hits): void
    {
        $hash = hash('md5', 'x' . $this->c . 'q'); // salted, so it differs from the cache key in the db
        $cacheability = in_array($this->p, ['public', 'private', 'no-cache']) ? $this->p : 'public';

        header("Last-Modified: " . gmdate("D, d M Y H:i:s", $this->t) . " GMT");
        // weak: Apache compresses the body and keeps the ETag
        header("ETag: W/\"$hash\"");
        header("Cache-Control: $cacheability");
        // maybe we can add max-age=60   (minute helps a lot on big loads (catches 99%), but is nothing for reader)

        $not_modified = self::notModified($this->h, $_SERVER, $hash, $this->t);
        if ($not_modified) {
            http_response_code(304);
        } else {
            AA::sendHeaders($this->h);
            echo $this->c;
        }
        if ($this->i) {
            Hitcounter::hit(new zids($this->i, 'l'), $cached, $not_modified);
        }
        foreach ($hits as $zids) {
            Hitcounter::hit($zids, $cached, $not_modified);
        }
    }

    /** Is the client's copy current (RFC 9110 §13)? $hash is the opaque part of our ETag, $headers the page's own.
     *  Only GET and HEAD: any other method has run already, before its preconditions could be checked
     *  (a 304 set by the template answers to any method) */
    public static function notModified(array $headers, array $server, string $hash, int $last_modified): bool
    {
        $status = preg_match('~^HTTP/\S+\s+(\d{3})~', $headers['status'] ?? '', $m) ? (int) $m[1] : 200;
        if ($status === 304) {   // {header:304}: a 304 must not carry the body
            return true;
        }
        // preconditions hold only for a page that would answer 2xx (§13.2.1)
        if (($status < 200) || ($status >= 300) || !in_array($server['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            return false;
        }
        $if_none_match = $server['HTTP_IF_NONE_MATCH'] ?? null;
        $if_modified_since = $server['HTTP_IF_MODIFIED_SINCE'] ?? null;
        if ($if_none_match !== null) {   // If-Modified-Since is ignored then
            if (trim($if_none_match) === '*') {
                return true;
            }
            // weak comparison, so a W/ prefix does not matter
            preg_match_all('~"([^"]*)"~', $if_none_match, $m);
            return in_array($hash, $m[1], true);
        }
        // only the exact date we sent: the URL has variants (cookies, login) rendered at other times,
        // so an older entry is not "unmodified" for a client holding a newer variant
        return ($if_modified_since !== null) && (strtotime($if_modified_since) === $last_modified);
    }
}
