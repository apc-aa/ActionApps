<?php
/**
 * File contains definition of inputform class - used for displaying input form
 * for item add/edit and other form utility functions
 *
 * Should be included to other scripts (as /admin/itemedit.php3)
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
use zids;

class Cacheentry
{
    protected $c = '';      // content
    protected $h = []; // headers array
    protected $i = '';      // item id for page hit
    protected $p = '';      // Cacheability - private/public/no-cache - private used for pages, where user is logged in
    protected $t = 0;       // timestamp

    function __construct($content, array $headers = [], $item_id = '', $cacheability = 'public')
    {
        $this->c = $content;
        $this->h = $headers;
        $this->i = $item_id;
        $this->p = $cacheability;
        $this->t = time();
    }

    /** send headers, print output and count hit */
    function processPage($cached = 0)
    {
        $lastModified = $this->t;     // get the last-modified-date of this very file
        $etag = hash('md5', 'x' . $this->c . 'q'); // get a unique hash of this content (etag) (make it not the same as key in db for (maybe paranoid) security reasons)
        $ifModifiedMatch = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $lastModified) : false;
        $etagMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (trim($_SERVER['HTTP_IF_NONE_MATCH']) == $etag) : false;   //(etag: unique file hash)
        $cacheability = in_array($this->p, ['public', 'private', 'no_cache']) ? $this->p : 'public';

        header("Last-Modified: " . gmdate("D, d M Y H:i:s", $lastModified) . " GMT");   // set last-modified header
        header("Etag: $etag");                                                      // set etag-header
        // maybe we do not have to cache all pages
        header("Cache-Control: $cacheability");                                     // make sure caching is turned on
        // maybe we can add max-age=60   (minute helps a lot on big loads (catches 99%), but is nothing for reader)

        // check if page has changed. If not, send 304 and exit
        // Maybe we should check also $this->h, if they do not contain 404, 302 or other status code already
        if ($ifModifiedMatch or $etagMatch) {
            header("HTTP/1.1 304 Not Modified");
            $cached = 1000 * $cached + 304;
        } else {
            AA::sendHeaders($this->h);
            echo $this->c;
        }
        if ($this->i) {
            Hitcounter::hit(new zids($this->i, 'l'), $cached);
        }
    }
}