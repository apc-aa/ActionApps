<?php
/** Logging functions
 *
 *  @TODO Convert all loging into some class
 *        Enable setting of log level online
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/


/*
Events logged into AA log
type            selector                        parameters
-------------------------------------------------------------------------------
BM_CREATE       bookmark id in profile table    name
BM_UPDATE       bookmark id in profile table    name
BM_RENAME       bookmark id in profile table    new_name:old_name
BM_DELETE       bookmark id in profile table    name
EMAIL_SENT      bookmark id/LIST/TEST           users:valid_emails:emails_sent
TOEXECUTE       object's class                  return code
ALERTS          howoften                        Start/email sent
HITCOUNT        type
ITEM_DEL        item_id
M_FLDI_VLD      module_id:field_id              msg:url                        // insert form field validation
LOGIN           module_id:field_id              msg:url                        // insert form field validation
AA_WARN
*/

class AA_Log {

    /** AA_Log::write function - Write log entry
     *  Static function
     *
     * @param              $event
     * @param string|array $selector - if array, stored as separate enties (for ITEM_DEL, ...)
     * @param string|array $params - if array, joined
     * @return bool
     */
    static function write($event, $selector = "", $params = "") {
        global $auth;

        if ( !AA_Log::isLogable($event) ) {
            return false;
        }

        if (is_array($params)) {
            $params = ParamImplode($params);
        }

        $rows = array();
        if (is_array($selector)) {
            foreach ($selector as $sel) {
                $rows[] = array('time'=>time(), 'user'=>$auth->auth['uid'], 'type'=>$event, 'selector'=>$sel, 'params'=>$params);
            }
        } else {
            $rows[] = array('time'=>time(), 'user'=>$auth->auth['uid'], 'type'=>$event, 'selector'=>$selector, 'params'=>$params);
        }

        AA::Metabase()->doInsertMulti('log', $rows);

        // with probability 1:1000 set up log cleanup
        if ( mt_rand(0,1000) == 1) {
            // we plan this tasks for future (tomorrow)
            // it should be enough to clean the logs once a day

            // clean all older than 40 days
            //                                                    what to clean                                         when to run
            with( new AA_Toexecute )->laterOnce(new AA_Log_Clenup(now() - (60*60*24*40)), array(), "AA_Log_Clenup", 10, now() + (60*60*24));
        }
        return true;
    }

    static function warn($selector) {
        AA_Log::write('AA_WARN', $selector, AA_Log::backtrack());
    }

    static function backtrack() {
        $ret = explode("\n", str_replace(AA_BASE_PATH, '', with( new Exception() )->getTraceAsString()));
        array_shift($ret);
        array_shift($ret);
        return join("\n", $ret);
    }

    static function context($selector='') {
        return ParamImplode(array($selector,str_replace(':','_',$_SERVER['REMOTE_ADDR']), ($_SERVER['HTTPS'] ? '' : 'http/'). "{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}"));
    }

    /** Is the event logable?
     **/
    static function isLogable($event_type) {
        /** By $DO_NOT_LOG array you are able to specify, which events you don't want
         *  to log - it's just like filter
         *  This should be list of all logable events (at least now - 2005-11-9)
         */
        $DO_NOT_LOG = array(
           // 'ALERTS',
           // 'BM_CREATE',
           // 'BM_DELETE',
           // 'BM_RENAME',
           // 'BM_UPDATE',
           // 'CSN',
           // 'CSV_IMPORT',
           // 'EMAIL_SENT',
           // 'FEED2ALL_0',
           // 'FEED2ALL_1',
           // 'FEED_ADD',
           // 'FEED_DEL',
           // 'FEED_DSBLE',
           // 'FEED_ENBLE',
           // 'FILE IMP:',
           // 'ITEM_FIELD_FILLED',
           // 'PAGECACHE',
           // 'TOEXECUTE'
           // 'HITCOUNT'
           // 'AA_WARN'
           // 'ITEM_DEL'
           // 'ITEM_NEW'
           // 'ITEM_UPD'
           // 'M_FLDI_VLD'
           // AA_WARN
           // LOGIN
           // PWD_REHASH
        );
        return !in_array($event_type, $DO_NOT_LOG);
    }

    /** getLastLogEvents function
     *  Get events from log
     * @param        $event - type of event
     * @param string $selector
     * @return array
     */
    static public function getLastLogEvents($event, $selector = "") {
        $conds = array(array('type',$event, (strpos($event, '%') === false) ? '=' : 'LIKE'));
        if ($selector != "") {
            $conds[] = array('selector',$selector);
        }
        return DB_AA::select1('SELECT * FROM log','', $conds, array('id-'));
    }

}

class AA_Log_Clenup implements \AA\Util\LaterInterface {

    var $time;
    var $type;

    function __construct($time, $type='') {
        $this->time = $time;
        $this->type = $type;
    }

    /** just plan item updates in chunks
     *  special function called from AA_Toexecute class - used for queued tasks (run form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Util\LaterInterface
     */
    public function toexecutelater($params=array()) {
        $where = array(array('time', $this->time, '<'));

        // delete also old records post2shtml table
        DB_AA::delete('post2shtml', $where);

        if ( $this->type ) {
            $where[] = array('type',$this->type);
        }
        DB_AA::delete('log', $where);

        // delete task with priority = 0 - considered as undoable (note the priority is decreased on each try of task execution.)
        $undoable_tasks = GetTable2Array('SELECT * FROM toexecute WHERE priority=0', 'NoCoLuMn');
        AA_Log::write('TOEXECUTE', 'cleanup', $undoable_tasks);
        DB_AA::delete('toexecute', array(array('priority',0,'i')));
    }
}




