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

# APC AA - Module main administration page

# used in init_page.php3 script to include config.php3 from the right directory
$directory_depth = '../';

require_once "../../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"] . "varset.php3";
require_once $GLOBALS["AA_INC_PATH"] . "formutil.php3";

# id of the editted module
$module_id = $slice_id;               # id in long form (32-digit hexadecimal
                                      # number)
$p_module_id = q_pack_id($module_id); # packed to 16-digit as stored in database


# Check permissions for this page.
# You should change PS_MODW_EDIT_CODE permission to match the permission in your
# module. See /include/perm_core.php3 for more details
 
if( !IfSlPerm(PS_MODW_EDIT_CODE) ) {
  MsgPage($sess->url(self_base())."index.php3", _m("You do not have permission to edit items in this slice"));
  exit;
}  


# fill code for handling the operations managed on this page


HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Editor window - item manager") ?></title>
</head> <?php

require_once "navbar.php3"; # module specific navigation bar
require_once "util.php3";   # module specific utils


# fill code for the main admin page layout


echo '
  </body>
</html>';

page_close();
exit;

/*
$Log$
Revision 1.3  2003/02/05 14:57:01  jakubadamek
changing require to require_once, deleting the "if (defined) return" constructs and changing GLOBALS[AA_INC_PATH] to GLOBALS["AA_INC_PATH"]

Revision 1.2  2003/01/17 10:38:34  jakubadamek
BIG CHANGES due to moving AA to use mini-gettext

Revision 1.1  2002/04/25 12:07:26  honzam
initial version

*/
?>