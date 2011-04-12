<?php
/**
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

/** User's tasks are planed for that time (2033-05-18 05:33:20) - it is never
 *  executed from cron
 **/
define( 'TOEXECUTE_USER_TASK_TIME', 2000000000);

/** AA_Toexecute class - used for many short tasks, such as sending an e-mail for
 *  alerts. Instead of sending thounsands of e-mails in one php script run (bad
 *  eperienses with 1000+ emails), we store just store the task in the database.
 *  Then we call misc/toexecute.php3 script from AA cron (say each 2 minutes)
 *  and if there is any task in the queue, it is executed. This way we spread
 *  sending of weeks alerts to thounsands users to several hours.
 *  Ussage:
 *    Instead of calling:
 *        $object->function_name(param1, param2);
 *    we will use
 *        $toexecute = new AA_Toexecute;
 *        $toexecute->later($object, array(param1, param2));
 *
 *        Then we create method $object->toexecutelater(param1, param2)
 *        in which we will call $object->function_name(param1, param2);
 *
 *  The name of 'toexecutelater' method is fixed - This is because of security.
 *  We do not want to allow users to execute any method of any object just
 *  by inserting some data in the database.
 */
class AA_Toexecute {

    var $messages = array();

    /** Message function
    * @param $text
    */
    function message($text) {
        if (is_array($text)) {
            $this->messages = array_merge($this->messages, $text);
        } else {
            $this->messages[] = $text;
        }
    }

    /** Report function
    * @return messages separated by <br>
    */
    function report()       {
        return join('<br>', $this->messages);
    }

    /** Clear report function - unsets all current messages  */
    function clear_report() {
        unset($this->messages);
        $this->messages = array();
    }


    /** global_instance function
     *  "class function" obviously called as AA_Toexecute::global_instance();
     *  This function makes sure, there is global instance of the class
     */
    function global_instance() {
        if ( !isset($GLOBALS['toexecute']) ) {
            $GLOBALS['toexecute'] = new toexecute;
        }
    }

    /** later function
     *  Stores the object and params to the database for later execution.
     *  Such task is called from cron (the order depends on priority)
     *  selector is used for identifying class of task - used for deletion
     *  of duplicated task
     * @param $object
     * @param $params
     * @param $seletor
     * @param $priority
     * @param $time
     *  @example: we need to recount all links in allcategories (Links module),
     *           so we need to cancel all older "recount" tasks, since it will
     *           be dubled in the queue (we call cancel_all() method for it)
     */
    function later( &$object, $params=array(), $selector='', $priority=100, $time=null ) {
        global $auth;
        $varset = new Cvarset(
            array( 'created'       => time(),
                   'execute_after' => (is_null($time) ? time() : $time),  // task for user queue uses $time=0 (which is not the same as null, of course)
                   'aa_user'       => $auth->auth['uid'],
                   'priority'      => $priority,
                   'selector'      => ($selector ? $selector : get_class($object)),
                   'object'        => serialize($object),
                   'params'        => serialize($params)
                  ));
         // store the task in the queue (toexecute table)
         if ( !$varset->doInsert('toexecute') ) {
             // if you can't store it in the queue (table not created?)
             // - execute it directly
             return $this->execute_one($object,$params);
         }
         return true;
    }

    /** before the task is planed, it check, if it is not already scheduled
     *  (from previous time). The task is considered as planed, if the SELECTORs
     *  are the same
     */
    function laterOnce( &$object, $params, $selector, $priority=100, $time=null ) {
        if ( !GetTable2Array("SELECT selector FROM toexecute WHERE selector='".quote($selector)."' AND priority > 0", 'aa_first', 'aa_mark')) {
            $this->later($object, $params, $selector, $priority, $time);
        }
    }

    /** User task queue - we use it for spliting one long task (which would take
     *  ages) into subtasks, so it could be executed separatelly - one after
     *  the another. Such tasks are dedicated to logged user and are displayed
     *  for him/her in Item Manager.
     *  Such task are marked with execute_after=TOEXECUTE_USER_TASK_TIME in the
     *  toexecute table (which also means, that such task are never autoexecuted
     *  from the cron)
     */
    function userQueue(&$object, $params, $selector, $priority=100 ) {
        $this->later( $object, $params, $selector, $priority, TOEXECUTE_USER_TASK_TIME );
    }

    /** cancel_all function
     * @param $selector
     */
    function cancel_all($selector) {
        $varset = new Cvarset;
        $varset->doDeleteWhere('toexecute',"selector='".quote($selector)."'");
    }

    /** execute function
     * @param $allowed_time
     */
    function execute($allowed_time = 0) {  // standard run is 16 s

        // get just ids - the task itself we will grab later, since the objects
        // in the database could be pretty big, so we want to grab it one by one
        // If the priority is 0, we consider this task as unpossible to execute,
        // because the script tries execute it several times and without success.
        // @todo such task should be removed by some garbage collector
        $tasks = GetTable2Array("SELECT id FROM toexecute WHERE execute_after < ".now()." AND priority > 0 ORDER by priority DESC", '', 'id');
        return $this->executeTask($tasks, $allowed_time);
    }

    /** Executes as many tasks from the $tasks array as time allows
     * @param $tasks - array of ids of tasks to execute
     * @param $allowed_time
     */
    function executeTask($tasks, $allowed_time = 0) {  // standard run is (max_execution_time - 9) seconds.

        if ( !$allowed_time ) {
            set_time_limit( 360 );   // try to set 360 seconds to run
            $allowed_time = (float) (defined('TOEXECUTE_ALLOWED_TIME' ) ? TOEXECUTE_ALLOWED_TIME : ((ini_get('max_execution_time')>0) ? ini_get('max_execution_time')-9 : 16.0));
        }

        /** there we store the the time needed for last task of given type
         *  (selector) - this value we use in next round to determine, if we can
         *  run one more such task or if we left it for next time */
        $execute_times = array();

        $this->clear_report();

        $execute_start = get_microtime();
        if (is_array($tasks)) {
            foreach ($tasks as $task_id) {
                $task = GetTable2Array("SELECT * FROM toexecute WHERE id='$task_id'", 'aa_first', 'aa_fields');

                $task_type     = get_if($task['selector'],'aa_unspecified');
                $expected_time = get_if($execute_times[$task_type], 1.0);  // default time expected for one task is 1 second
                $task_start    = get_microtime();

                // can we run next task? Does it (most probably) fit in allowed_time?
                if ( (($task_start + $expected_time) - $execute_start) > $allowed_time) {
                    break;
                }
                $varset = new Cvarset( array( 'priority' => max( $task['priority']-1, 0 )));
                $varset->addkey('id', 'number', $task['id']);
                // We lower the priority for this task before the execution, so
                // if the task is not able to finish, then other tasks with the same
                // priority is called before this one (next time)
                $varset->doUpdate('toexecute');

                $object = unserialize($task['object']);
                if ( $GLOBALS['debug'] ) {
                    huhl($object);
                }
                $retcode = $this->execute_one($object, unserialize($task['params']));
                $this->message($retcode);

                // Task is done - remove it from queue
                $varset->doDelete('toexecute');
                $execute_times[$task_type] = get_microtime() - $task_start;
                AA_Log::write('TOEXECUTE', $execute_times[$task_type]. ":$retcode:".$task['params'], get_class($object));
            }
        }
    }

    /** execute_one function
     * @param $object
     * @param $params
     */
    function execute_one(&$object, $params) {
        if ( !is_object($object) ) {
            return 'No object'; // Error
        }
        set_time_limit(max(30,ini_get('max_execution_time')));   // 30 seconds (at least) for each task
        return call_user_func_array(array($object, 'toexecutelater'), (array)$params);
    }
} // end of toexecute class

?>
