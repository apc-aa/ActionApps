<?php
//$Id$
/*
Copyright (C) 2003 Mitra Technology Consulting
http://www.mitra.biz

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


/*
    This command is like /view.php3, except it runs within the Admin
    menus, allowing views to be used to define administrative functions.
*/



require_once "../include/init_page.php3"; // Loads variables etc
//require_once $GLOBALS["AA_INC_PATH"]."sliceobj.php3";  // for slices

// These parameters effect how slices compare to each other

HtmlPageBegin();
?>
 <TITLE><?php echo _m("Administrative view");?></TITLE>
</HEAD>

<?php

/* Fix these shortcuts later */
    if (!$supmenu) $supmenu = "itemmanager";
    if (!$submenu) $submenu = $r_state['bin'];
    if (!$submenu) $submenu = "app";


    require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
    showMenu($aamenus, "$supmenu","$submenu");

    if ($time_limit) set_time_limit($time_limit);
    if ($contenttype) {
	    header("Content-type: $contenttype");
    }
    echo GetView(ParseViewParameters());
  
    HtmlPageEnd();
    page_close();
?>