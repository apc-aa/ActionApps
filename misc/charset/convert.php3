<?php
/**
 * The file is used to convert file character encoding to another one.
 * Usage:
 * <!--#include virtual="/apc-aa/misc/charset/convert.php3?from=iso-8859-1&to=utf-8&source=http%3A%2F%2Fwww.apc.org%2Fpage.htm" -->
 *
 * @param from   - source encoding      (default is iso-8859-1)
 * @param to     - destination encoding (default is utf-8)
 * @param source - file (url) to be converted. In examle above we convert
 *                 http://www.apc.org/page.htm  (url encoded)
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
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

// must be defined before include of convert_charset.class.php3
$convert_tables_path = "../../include/ConvertTables/";
require_once "../../include/convert_charset.class.php3";

$from   = ($_REQUEST['from'] ? $_REQUEST['from'] : 'iso-8859-1');
$to     = ($_REQUEST['to']   ? $_REQUEST['to']   : 'utf-8');
$source = $_REQUEST['source'];
if ( !$source ) {
    echo "<--! source not specified -->";
    exit;
}

if ((substr($source,0,7) != 'http://') AND (substr($source,0,8) != 'https://')) {
    echo "<--! only http:// (or https://) sources are accepted (for security reasons) -->";
    exit;
}

$encoder = new ConvertCharset;
echo $encoder->Convert(file_get_contents($source), $from, $to);

?>





