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

#
# Logging functions
#

/*

Events logged into AA log
type            selector                        parameters
-------------------------------------------------------------------------------
BM_CREATE       bookmark id in profile table    name
BM_UPDATE       bookmark id in profile table    name
BM_RENAME       bookmark id in profile table    new_name:old_name
BM_DELETE       bookmark id in profile table    name
EMAIL_SENT      bookmark id/LIST/TEST           users:valid_emails:emails_sent

*/

# Write log entry
function writeLog($event, $params="", $selector="" ) {
    global $auth, $LOG_EVENTS;
    $db = getDB();

    if (is_array($params)) {
      $params = ParamImplode($params);
    }

    $event    = addslashes($event);
    $params   = addslashes($params);
    $selector = addslashes($selector);

    $SQL = "INSERT into log SET time='". time() ."',
                                user='". $auth->auth["uid"] ."',
                                type='$event',
                                selector='$selector',
                                params='$params'";
    $db->query($SQL);
    freeDB($db);
}

# get events from log
# event - type of event
# from - events from date
# to - events to date
# group_by_params - if true, returns events grouped by params and their count as count
# delete_old_logs -
function getLogEvents($event, $from="", $to="", $group_by_param=false, $delete_old_logs=false, $selector="") {

    $time = time();

    if (strpos($event, '%') == false) { $like = false; } else { $like = true; }

    // if "to" isn't set, we use time of query, because of saving log entries
    // written in (and after) query
    if ($to == "") { $to = $time; }

    if ($selector != "") { $slctr = " AND selector = '$selector'"; }

    if ($group_by_param) {
        $SQL = "SELECT *,COUNT(*) AS count FROM log WHERE type". ($like ? " LIKE " : "=") ."'$event'";
        if ($from) { $SQL .= " AND time >= '$from'"; }
        if ($to) { $SQL .= " AND time <= '$to'";}
        if ($slctr) { $SQL .= $slctr; }
        $SQL .= " GROUP BY params";
    } else {
        $SQL = "SELECT * FROM log WHERE type". ($like ? " LIKE " : "=") ."'$event'";
        if ($from) { $SQL .= " AND time >= '$from'"; }
        if ($to) { $SQL .= " AND time <= '$to'"; }
        if ($slctr) { $SQL .= $slctr;  }
        // $SQL .= "ORDER BY TIME";
    }
    $return = GetTable2Array($SQL);

    // remove old log entries from table
    if ($delete_old_logs) {
        $SQL = "DELETE FROM log WHERE type='$event'";
        if ($from) { $SQL .= " AND time >= '$from'"; }
        if ($to) { $SQL .= " AND time <= '$to'"; }
        if ($slctr) { $SQL .= $slctr;  }
        tryQuery($SQL);
    }

    return $return;

}


?>