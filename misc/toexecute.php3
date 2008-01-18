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

/** APC-AA configuration file */
require_once "../include/config.php3";
/** Main include file for using session management function on page */
require_once AA_INC_PATH."locsess.php3";
/** Defines class for inserting and updating database fields */
require_once AA_INC_PATH."varset.php3";

//require_once AA_INC_PATH."item.php3";
//require_once AA_INC_PATH."view.php3";
require_once AA_INC_PATH."pagecache.php3";
//require_once AA_INC_PATH."searchlib.php3";
require_once AA_INC_PATH.  "toexecute.class.php3";
require_once AA_INC_PATH.  "mail.php3";
require_once AA_BASE_PATH. "modules/links/cattree.php3";
require_once AA_INC_PATH.  "hitcounter.class.php3";

/** This script is possible to run from commandline (so also from cron). The
 * benefit is, that the script then can run as long as you want - it is not
 * stoped be Apache after 2 minutes or whatever is set in TimeOut
 * The commandline could look like:
 *   # php toexecute.php3
 * or with 'nice' and allowing safe_mode (for set_time_limit) and skiping to
 * right directory for example:
 *   # cd /var/www/example.org/apc-aa/misc && nice php -d safe_mode=Off toexecute.php3
 * The command above could be used from cron.
 */

$toexecute = new AA_Toexecute;

/*
$mail = new HtmlMail;
$mail->setText("toto je mail 10");
$mail->setSubject('subject');
//$mail->setBasicHeaders(ecord, "");
$mail->setTextCharset($LANGUAGE_CHARSETS['en']);
$mail->setHtmlCharset($LANGUAGE_CHARSETS['en']);
huhl($mail);
$toexecute->later($mail,array(array('rrrrrrrrrrrx@ecn.cz')));
*/


$toexecute->execute();

?>

