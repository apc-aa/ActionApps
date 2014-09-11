<?php
//$Id$
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

// APC AA - Module main administration page

// used in init_page.php3 script to include config.php3 from the right directory

require_once "../../include/init_page.php3";
require_once AA_INC_PATH . "varset.php3";
require_once AA_INC_PATH . "view.php3";
require_once AA_INC_PATH . "pagecache.php3";

is_object( $db ) || ($db = getDB());
$db->query("SELECT * FROM jump WHERE slice_id = '".q_pack_id($slice_id)."'");
if ($db->next_record()) {
    $dest = $db->f("destination");
    if (strchr ($dest,"?")) $dest .= "&"; else $dest .= "?";
    $instal_url = AA_INSTAL_PATH;
    $url = $sess->url($instal_url.$dest);
    $change_id = $db->f("dest_slice_id");
    if ($change_id) $url .= "&jumping=1&change_id=".unpack_id($change_id);
    //echo $url; exit;
    go_url ($url);
}
else
    echo "<HTML><BODY>Something is wrong. I didn't find any record for this slice in the <B>jump</B> table.</BODY></HTML>";

page_close();
?>