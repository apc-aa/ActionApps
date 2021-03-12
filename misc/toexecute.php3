<?php
/**
 * Gets first x (20) entries (tasks) from toexecute table (=task queue) - sorted
 * by priority and executes it. It is used for tasks, which is relatively easy
 * to do, but there is a lot of such tasks to do. For example - sending e-mails
 * to all people from Reader slice (Alerts)
 * To be called directly or by Cron.
 * Parameter: none
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>, Econnect, December 2004
 * @copyright Copyright (C) 1999-2004 Association for Progressive Communications
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

/** APC-AA configuration file */

use AA\IO\DB\DB_AA;
use AA\Later\ScheduleAaTasks;
use AA\Later\Toexecute;

require_once __DIR__."/../include/config.php3";
/** Main include file for using session management function on page */
require_once __DIR__."/../include/locsess.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/util.php3";
require_once __DIR__."/../include/mail.php3";
require_once __DIR__."/../modules/links/cattree.php3";

/** This script is possible to run from commandline (so also from cron). The
 * benefit is, that the script then can run as long as you want - it is not
 * stopped be Apache after 2 minutes or whatever is set in TimeOut
 * The commandline could look like:
 *   # php toexecute.php3
 * or with 'nice' and allowing safe_mode (for set_time_limit) and skipping to
 * right directory for example:
 *   # cd /var/www/example.org/apc-aa/misc && nice php -d safe_mode=Off toexecute.php3
 * The command above could be used from cron.
 */


// first of all - make sure all AA maintenance tasks are scheduled
(new ScheduleAaTasks())->planAaTasks();

$toexecute = new Toexecute;

/*
$mail = new AA_Mail;
$mail->setText("toto je mail 10");
$mail->setSubject('subject');
//$mail->setBasicHeaders(ecord, "");
$mail->setTextCharset('utf-8');
$mail->setHtmlCharset('utf-8');
huhl($mail);
$toexecute->later($mail,array(array('rrrrrrrrrrrx@ecn.cz')));
*/

// notify functions based on ANONYMOUS_EDIT_CRON permission
AA::$perm->setPermMode('cron');

$toexecute->execute();


if (AA::$debug) {
    echo '<br>Dababase instances: '. DB_AA::$_instances_no;
    echo '<br>UsedModules:<br> - '. join('<br> - ', array_map(function($mid) {return AA_Module::getModuleName($mid);}, AA_Module::getUsedModules()));
    AA::$dbg->duration_stat();
}





