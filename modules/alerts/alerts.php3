<?php
/**
 * Sends Alerts messages with new items to users.
 * To be called directly or by Cron.
 * Parameter:
 *     $howoften
 * 
 * @package Alerts
 * @version $Id$
 * @author Jakub Adámek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications 
*/
/* 
Copyright (C) 1999-2002 Association for Progressive Communications 
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

require_once "./lang.php3";
require_once $GLOBALS["AA_INC_PATH"]."item.php3";
require_once $GLOBALS["AA_INC_PATH"]."view.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."searchlib.php3";
require_once "alerts_sending.php3";

//$debug = 1;

if (!is_object ($db)) $db = new DB_AA;

$howoften_options = get_howoften_options();

if ($howoften_options[$howoften]) {
    initialize_last();
    //echo "<h1>$ho</h1>";
    $mail_count = send_emails($howoften, "all", "all", true, "");
    //echo "<br>Count of emails sent is <b>".($mail_count+0)."</b><br>";
}

?>

