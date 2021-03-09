<?php
/**
 * Sends Alerts messages with new items to users.
 * To be called directly or by Cron.
 * Parameter:
 *     $howoften
 *
 * @package Alerts
 * @version $Id$
 * @author Jakub Ad�mek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications
*/
/*
Copyright (C) 1999-2002 Association for Progressive Communications
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

use AA\IO\DB\DB_AA;

if (!defined ("AA_ID")) {
    require_once __DIR__."/../../include/config.php3";
}
require_once __DIR__."/../../include/locsess.php3";
require_once __DIR__."/../../include/util.php3";
require_once __DIR__."/../../include/varset.php3";
// mini gettext language environment (the _m() function)
require_once __DIR__."/../../include/mgettext.php3";

if (!$lang) {
    $lang = "en";
}
mgettext_bind($lang, 'alerts');
require_once __DIR__."/util.php3";


require_once __DIR__."/../../modules/alerts/lang.php3";
require_once __DIR__."/../../include/item.php3";
require_once __DIR__."/../../include/view.php3";

require_once __DIR__."/../../include/searchlib.php3";
require_once __DIR__."/../../modules/alerts/alerts_sending.php3";


/** This script is possible to run from commandline (so also from cron). The
 * benefit is, that the script then can run as long as you want - it is not
 * stoped be Apache after 2 minutes or whatever is set in TimeOut
 * The commandline could look like:
 *   # php alert.php3 howoften=weekly
 * or with 'nice' and allowing safe_mode (for set_time_limit) and skiping to
 * right directory for example:
 *   # cd /var/www/example.org/apc-aa/modules/alerts && nice php -d safe_mode=Off alerts.php3 howoften=weekly
 * The command above could be used from cron.
 */

// get 'howoften' parameter from comandline, if it is specified.

if (isset($_SERVER["argv"] )) {
    $cmd_parameters = [];
    parse_str( implode('&',$_SERVER["argv"]), $cmd_parameters );
    if ( $cmd_parameters['howoften'] ) {
        $howoften = $cmd_parameters['howoften'];
    }
    // $fix - if set, then send only e-mail to not processed collections
    if ( $cmd_parameters['fix'] ) {
        $fix      = $cmd_parameters['fix'];
    }
}

$frequency['daily']     = 24 * 60 * 60;
$frequency['weekly']    =  7 * $frequency['daily'];
$frequency['twoweeks']  = 14 * $frequency['daily'];
$frequency['monthly']   = 31 * $frequency['daily'];
//$frequency['irregular']  = ...;   // not specified - used for irregular sending - see AA_Response('Alertitem', ...

if (!is_object($db)) $db = new DB_AA(['type' => DB_TYPE, 'host' => DB_HOST, 'database' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASSWORD]);

$howoften_options = get_howoften_options();

if ($howoften_options[$howoften]) {
    initialize_last();
    if ( $fix == 1) {
         // send only e-mail to not processed collections
         $SQL = "SELECT collectionid FROM alerts_collection_howoften WHERE howoften = '$howoften' AND last < ". (time() - $frequency[$howoften]);
         $colections = GetTable2Array($SQL, '', 'collectionid');
    } else {
        $colections = 'all';
    }
    AA_Log::write("ALERTS", $howoften, 'Start' . ($fix ? '(fix ' . join(',', $colections) . ')' : '(all)'));
    $mail_count = send_emails($howoften, $colections, "all", true, "");
    AA_Log::write("ALERTS", $howoften, 'Sent: ' . ($mail_count + 0));
    //echo "<br>Count of emails sent is <b>".($mail_count+0)."</b><br>";
}



