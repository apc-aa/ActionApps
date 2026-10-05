<?php

/**
 * The file is used to convert file character encoding to another one.
 * Usage:
 * <!--#include virtual="/apc-aa/misc/charset/convert.php?from=iso-8859-1&to=utf-8&source=http%3A%2F%2Fwww.apc.org%2Fpage.htm" -->
 *
 * @param $from   - source encoding      (default is iso-8859-1)
 * @param $to     - destination encoding (default is utf-8)
 * @param $source - file (url) to be converted. In example above we convert
 *                 https://www.apc.org/page.htm  (url encoded)
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

require_once __DIR__ . "/../../include/convert_charset.class.php";
require_once __DIR__ . "/../../vendor/autoload.php";

$from   = ($_REQUEST['from'] ?? '') ?: 'iso-8859-1';
$to     = ($_REQUEST['to'] ?? '') ?: 'utf-8';
$source = $_REQUEST['source'] ?? '';
// the names pick a conversion table file - nothing but a plain name may reach the path
if (!is_string($from) || !is_string($to) || !preg_match('/^[A-Za-z0-9_-]+$/', $from . $to)) {
    echo "<!-- unknown encoding -->";
    exit;
}
if (!is_string($source) || ($source === '')) {
    echo "<--! source not specified -->";
    exit;
}

if (!str_starts_with($source, 'http://') && !str_starts_with($source, 'https://')) {
    echo "<--! only http:// (or https://) sources are accepted (for security reasons) -->";
    exit;
}

// an address of the server's own network is refused - anyone can call this file
try {
    $answer = AA\Http\Client::publicOnly(timeout: 30, idle: 30, redirects: 5, maxBytes: 10 * 1024 * 1024)->request('GET', $source);
} catch (AA\Http\TransportException|AA\Http\ResponseTooLargeException) {
    echo "<!-- source not readable -->";
    exit;
}

// a foreign page on AA's own origin: opened directly, it must not run its scripts here
// (an SSI include does not pass this header on to the visitor)
header('Content-Security-Policy: sandbox');
$encoder = new ConvertCharset();
echo $encoder->Convert($answer->ok() ? $answer->body : '', $from, $to);
