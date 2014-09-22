<?php
/**
 * PHP cron: reads items from database and runs them. The item format is UNIX-cron-like.
 *
 * DOCUMENTATION: See cron.php3 documentation in {@link http://apc-aa.sourceforge.net/faq/#401 FAQ pages}.
 *
 * UNIX Cron documentation:
 *<pre>
 * Field          Allowed Values
 * -----          --------------
 * Minute         0-59
 * Hour           0-23
 * Day of Month   1-31
 * Month          1-12, jan, feb, mar, apr, may, jun, jul, aug, sep, oct,
 *                nov, dec
 * Day of Week    0-7, sun, mon, tue, wed, thu, fri, sat (0 and 7 are "sun")
 * </pre>
 * A field may be an asterisk (*), which indicates all values in the range are acceptable. Ranges of numbers are allowed, i.e. "2-5" or "8-11", and lists of numbers are allowed, i.e. "1,3,5" or "1,3,8-11". Step values can be represented as a sequence, i.e. "0-59/15", "1-31/3", or "* /2".
 *
 *  @package UserOutput
 *  @version $Id$
 *  @author Jakub Adamek <jakubadamek@ecn.cz>
 *  @copyright Econnect, Jakub Adamek, February 2002
*/
/*
Copyright (C) 2002 Association for Progressive Communications
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
require_once "./include/config.php3";
/** Main include file for using session management function on page */
require_once AA_INC_PATH."locsess.php3";
/** Defines class for inserting and updating database fields */
require_once AA_INC_PATH."varset.php3";

# zvetseni limitu - neprojde rozeslani vsech emailu
if( !ini_get('safe_mode') ){
  set_time_limit(3600);
}

/**
 * Runs the items from the cron table if their time has come.
 * You may call cron with specified timestamp to simulate the behavior.
 * DOCUMENTATION: See cron.php3 documentation in {@link http://apc-aa.sourceforge.net/faq/#401 FAQ pages}.
 * @param int $time the UNIX timestamp to simulate behaviour on a given date
 *                  - if omitted, uses the current time
 */
function cron($time = 0) {

    global $debug;
    if ($debug) {
        echo "<HTML><BODY>";
        echo "DEBUGGING. I don't run any script!<br>";
    }

    $translate["mon"] = array ("jan"=>1,"feb"=>2,"mar"=>3,"apr"=>4,"may"=>5,"jun"=>6,"jul"=>7,"aug"=>8,"sep"=>9,"oct"=>10,"nov"=>11,"dec"=>12);
    $translate["wday"] = array ("sun"=>0,"mon"=>1,"tue"=>2,"wed"=>3,"thu"=>4,"fri"=>5,"sat"=>6,"7"=>0);

    if (!$time) $time = time();

    $db = getDB();

    $parts = array ("mon"=>12,"wday"=>7,"mday"=>31,"hours"=>24,"minutes"=>60);
    if ($debug) { echo "<B>".date("d.m.y H:i",$time)."</B></BR>"; }

    $db->query("SELECT * FROM cron");
    while ($db->next_record()) {
        /*  $nearest is the nearest of times on which an item should have run to now (nearest <= now)
            I don't consider last_run when looking for $nearest, but afterwards I run the script only
            if $nearest > $last_run.

            $nearest_part is value of current part of nearest */
        $nearest_part = 0;

        // when last_run is NULL, run item always
        if ($db->f("last_run")) {
            $last_run = getdate ($db->f("last_run"));

            // If an hour passed, I want to have minutes as 60+minutes etc.
            $now = getdate ($time);
            $now["mon"] += 12 * ($now["year"]-$last_run["year"]);

            // If the month(s) changed, find how many days it had
            $now_tst = mktime ($last_run["hours"],$last_run["minutes"],$last_run["seconds"],
                $now["mon"],$last_run["mday"],$now["year"]);
            $days_in_months = ($now_tst - $db->f("last_run")) / (60 * 60 * 24);

            $now["wday"] += $days_in_months;
            $now["mday"] += $days_in_months;
            $now["hours"] += 24 * ($now["mday"]-$last_run["mday"]);
            $now["minutes"] += 60 * ($now["hours"]-$last_run["hours"]);

            $realnow = getdate ($time);

            if ($debug > 1) {
                print_r ($last_run); echo "<br>";
                print_r ($now); echo "<br>";
            }

            reset ($parts);
            while ((list($part) = each($parts)) && $nearest_part > -1) {
                $now_part = $now[$part];

                $value = $db->f($part);
                if ($value == "*") {
                    $nearest[$part] = $realnow[$part];
                    continue;
                }
                $nearest_part = -1;

                $tr = $translate[$part];
                if (is_array($tr)) {
                    reset ($tr);
                    while (list($search,$replace) = each($tr))
                        $value = str_replace ($search,$replace,$value);
                }

                if (strstr($value,'/')) {
                    list($from,$to,$step) = preg_split('~[/-]~',$value);
                    for ($i = $from; $i <= $to; $i += $step)
                        if ($i <= $now_part && $i > $nearest_part)
                            $nearest_part = $i;
                }
                else {
                    $ranges = explode(',',$value);
                    reset ($ranges);
                    while (list(,$range) = each ($ranges)) {
                        if (strstr ($range,"-")) {
                            list($from,$to) = explode('-',$value);
                                for ($i = $from; $i <= $to; $i++)
                                if ($i <= $now_part && $i > $nearest_part)
                                    $nearest_part = $i;
                        }
                        else if ($range <= $now_part && $range > $nearest_part)
                            $nearest_part = $range;
                    }
                }
                $nearest[$part] = $nearest_part;
            } // end of while
            $nearest_time = mktime ($nearest["hours"], $nearest["minutes"],0, $nearest["mon"], $nearest["mday"], $now["year"]);
            // mktime does not consider week day. We must shift it manually to the desired week day.
            // This will not work correct when combining week day with month or month day settings.
            if ($db->f("wday") != "*") {
                $wday = getdate ($nearest_time);
                $diff = (7 - $nearest["wday"] + $wday["wday"]) % 7;
                $nearest_time -= $diff * 24 * 60 * 60;
            }
        } // end of if ($db->f("last_run"))
        else {
            $nearest_time = time();
        }

        $url = AA_INSTAL_URL.$db->f("script")."?".$db->f("params");

        if ($debug) {
            if ($nearest_part > -1 && $nearest_time > $db->f("last_run")) {
                 echo "<b>$url</b> will be run<BR>";
            } else {
                echo "<font size=small>$url will be run on ".date( "d.m.y H:i",$nearest_time)." ($nearest_time)</font><BR>";
            }
            //echo "Nearest time: "; print_r ($nearest);
            DB_AA::sql("UPDATE cron SET last_run=".$time." WHERE id=".$db->f("id"));
        }
        elseif ($nearest_part > -1 && $nearest_time > $db->f("last_run")) {
            DB_AA::sql("UPDATE cron SET last_run=".$time." WHERE id=".$db->f("id"));
            fopen ($url,"r");
        }
    }
    if ($debug) echo "</BODY></HTML>";
}

// Use this for debug purposes
/*
is_object( $db ) || ($db = getDB());
$db->query("UPDATE cron SET last_run = NULL");
$debug = 1;

$time = time();
for ($i = 0; $i < 50; $i++) {
    cron ($time);
    $time += 60*60*24;
}
*/

cron();
?>
