<?php
/**
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
 */

/** User's tasks are planed for that time (2033-05-18 05:33:20) - it is never
 *  executed from cron
 **/

namespace AA\Later;

use AA;
use AA\IO\DB\DB_AA;
use AA_Log;
use Cvarset;

/** AA\Later\Toexecute class - used for many short tasks, such as sending an e-mail for
 *  alerts. Instead of sending thounsands of e-mails in one php script run (bad
 *  eperienses with 1000+ emails), we store just store the task in the database.
 *  Then we call misc/toexecute.php script from AA cron (say each 2 minutes)
 *  and if there is any task in the queue, it is executed. This way we spread
 *  sending of weeks alerts to thounsands users to several hours.
 *  Usage:
 *    Instead of calling:
 *        $object->function_name(param1, param2);
 *    we will use
 *        $toexecute = new AA\Later\Toexecute;
 *        $toexecute->later($object, array(param1, param2));
 *
 *        Then we create method $object->toexecutelater(param1, param2)
 *        in which we will call $object->function_name(param1, param2);
 *
 *  The name of 'toexecutelater' method is fixed - This is because of security.
 *  We do not want to allow users to execute any method of any object just
 *  by inserting some data in the database.
 */
class Toexecute {
    use \AA\Util\ReportTrait;

    const TOEXECUTE_USER_TASK_TIME =  2000000000;

    /** later function
     *  Stores the object and params to the database for later execution.
     *  Such task is called from cron (the order depends on priority)
     *  selector is used for identifying class of task - used for deletion
     *  of duplicated task
     * @param        $object
     * @param array $params
     * @param string $selector
     * @param int $priority
     * @param        $time
     * @return bool|mixed|string
     * @example: we need to recount all links in all categories (Links module),
     *           so we need to cancel all older "recount" tasks, since it will
     *           be doubled in the queue (we call cancel_all() method for it)
     */
    function later($object, $params = [], $selector = '', $priority = 100, $time = null)
    {
        $varset = new Cvarset([
            ['created', time()],
            ['execute_after', ((int)$time < time() ? time() : $time)],  // task for user queue uses TOEXECUTE_USER_TASK_TIME
            ['aa_user', AuthGetUid()],
            ['priority', $priority],
            ['selector', ($selector ?: get_class_name($object, true))],
            ['object', serialize($object)],
            ['params', serialize($params)]
        ]);

        // store the task in the queue (toexecute table)
        if (!$varset->doInsert('toexecute')) {
            // if you can't store it in the queue (table not created?)
            // - execute it directly
            return $this->execute_one($object, $params);
        }
        return true;
    }

    /** before the task is planed, it check, if it is not already scheduled
     *  (from previous time). The task is considered as planed, if the SELECTORs
     *  are the same
     * @param object   $object
     * @param array    $params
     * @param string   $selector
     * @param int      $priority
     * @param int|null $time
     * @param bool     $just_once - this task (based on selector) is (re)planed only once in script execution (AA_Mysqlauth_Maintain we want to schedule once - not on every deleted item))
     */
    function laterOnce(object $object, array $params, string $selector, int $priority = 100, ?int $time = null, bool $just_once=false) {
        static $scheduled = [];  // selector => true for tasks where $just_once is true

        if ($just_once) {
            if (isset($scheduled[$selector])) {
                return;
            } else {
                $scheduled[$selector] = true;
            }
        }

        $time = (int)$time < time() ? time() : $time; // the same as in later()
        $task = self::scheduledTask($selector);  // ['id' => $id, 'time' => $time]
        if (!$task['id']) {
            $this->later($object, $params, $selector, $priority, $time);
        } elseif ( $task['time'] > $time) {
            // we need it run earlier
            DB_AA::update('toexecute', [['execute_after' => $time]], [['id', $task['id'], 'i']]);
        }
    }

    /** @returns array scheduled ['id'=>.., 'time'=>..] or ['id'=>false, 'time'=>false]
     *  @usage  ['id' => $id, 'time' => $time] = self::scheduledTask($selector);
     */
    static function scheduledTask($selector): array {
        $ret = DB_AA::select1([], "SELECT `id`,`execute_after` FROM `toexecute`", [['selector', $selector], ['priority', 0, '>']]);
        return $ret ?: ['id'=>false, 'time'=>false];
    }


    /** User task queue - we use it for splitting one long task (which would take
     *  ages) into subtasks, so it could be executed separately - one after
     *  the another. Such tasks are dedicated to logged user and are displayed
     *  for him/her in Item Manager.
     *  Such tasks are marked with execute_after=TOEXECUTE_USER_TASK_TIME in the
     *  toexecute table (which also means, that such task are never autoexecuted
     *  from the cron)
     */
    function userQueue($object, $params, $selector, $priority = 100)
    {
        $this->later($object, $params, $selector, $priority, Toexecute::TOEXECUTE_USER_TASK_TIME);
    }

    /** cancel_all function
     * @param $selector
     */
    function cancel_all($selector)
    {
        $varset = new Cvarset;
        $varset->doDeleteWhere('toexecute', "selector='" . quote($selector) . "'");
    }

    /** execute function - function called periodically from /misc/toexecute.php
     */
    function execute()
    {

        // MoreTimeLimit();  // try to set 5 minutes to run
        $allowed_time = (float)(defined('TOEXECUTE_ALLOWED_TIME') ? TOEXECUTE_ALLOWED_TIME : ((ini_get('max_execution_time') > 0) ? ini_get('max_execution_time') - 9 : 16.0));

        /** there we store the time needed for last task of given type
         *  (selector) - this value we use in next round to determine, if we can
         *  run one more such task or if we left it for next time */
        $execute_times = [];         /** [string => float]  */
        $this->clear_report();
        $execute_start = microtime(true);
        $count = 0;
        $started = 0;

        // get just ids - the task itself we will grab later, since the objects
        // in the database could be pretty big, so we want to grab it one by one
        // If the priority is 0, we consider this task as impossible to execute,
        // because the script tries execute it several times and without success.
        // @todo such task should be removed by some garbage collector

        while ($task = DB_AA::select1('', 'SELECT * FROM `toexecute`', [['execute_after', time(), '<'], ['priority', 0, '>']], ['priority-'])) {
            ++$started;
            if ($this->_exeOne($task, $execute_times, $execute_start, $allowed_time)) {
                ++$count;
            } else {
                break;
            }
        }
        AA_Log::write('TOEXECUTE', 'AA\Later\Toexecute', "finished $count/$started in " . sprintf('%.3g', microtime(true) - $execute_start) . "/$allowed_time");
    }

    /** Executes as many tasks from the $tasks array as time allows
     * @param $tasks - array of ids of tasks to execute
     */
    function executeTask($tasks)
    {

        if (!is_array($tasks) or !count($tasks)) {
            return;
        }

        set_time_limit(360);   // try to set 360 seconds to run
        $allowed_time = (float)(defined('TOEXECUTE_ALLOWED_TIME') ? TOEXECUTE_ALLOWED_TIME : ((ini_get('max_execution_time') > 0) ? ini_get('max_execution_time') - 9 : 16.0));
        /** there we store the time needed for last task of given type
         *  (selector) - this value we use in next round to determine, if we can
         *  run one more such task or if we left it for next time */
        $execute_times = [];       /** [string => float]  */
        $this->clear_report();
        $execute_start = microtime(true);
        $count = 0;
        $started = 0;

        foreach ($tasks as $task_id) {
            $task = DB_AA::select1('', 'SELECT * FROM toexecute', [['id', $task_id, 'i']]);
            ++$started;
            if ($this->_exeOne($task, $execute_times, $execute_start, $allowed_time)) {
                ++$count;
            } else {
                break;
            }
        }
        AA_Log::write('TOEXECUTE', 'AA\Later\Toexecute', "finished $count/$started in " . sprintf('%.3g',microtime(true) - $execute_start) . "/$allowed_time");
    }

    function _exeOne($task, &$execute_times, $execute_start, $allowed_time)
    {
        $task_type = get_if($task['selector'], 'aa_unspecified');
        $expected_time = (float)get_if($execute_times[$task_type], 1.0);  // default time expected for one task is 1 second
        $task_start = microtime(true);

        // can we run next task? Does it (most probably) fit in allowed_time?
        if ((($task_start + $expected_time) - $execute_start) > $allowed_time) {
            return 0;
        }
        $varset = new Cvarset([['priority', max($task['priority'] - 1, 0)]]);
        $varset->addkey('id', 'number', $task['id']);
        // We lower the priority for this task before the execution, so
        // if the task is not able to finish, then other tasks with the same
        // priority is called before this one (next time)
        $varset->doUpdate('toexecute');

        $object = unserialize($task['object']);
        if (is_object($object) and !is_a($object, '__PHP_Incomplete_Class', false)) {
            $retcode = $this->execute_one($object, unserialize($task['params']));
            $this->message($retcode);
            $selector = get_class_name($object, true);
        } else {
            $this->message('Toexecute:execute - unserialize err:' . $task['object']);
            $retcode = 'no execute, removed from queue';
            $selector = 'no object:'.$task['object'];
        }

        // Task is done - remove it from queue
        $varset->doDelete('toexecute');
        $execute_times[$task_type] = microtime(true) - $task_start;

        $mem_usage = sprintf('%.3g',(memory_get_usage() / (1024*1024)));
        $mem_peak  = sprintf('%.3g',(memory_get_peak_usage() / (1024*1024)));

        AA_Log::write('TOEXECUTE', $selector, sprintf('%.3g', $execute_times[$task_type]).":{$mem_usage}MB:{$mem_peak}MB:$retcode:" . $task['params']);
        return 1;
    }

    /** execute_one function
     * @param $object
     * @param $params
     * @return mixed|string
     */
    function execute_one($object, $params)
    {
        if (!is_object($object) or is_a($object, '__PHP_Incomplete_Class', false)) {
            return 'Toexecute:execute_one: No object: ' . serialize($object); // Error
        }
        set_time_limit(max(30, ini_get('max_execution_time')));   // 30 seconds (at least) for each task
        return call_user_func([$object, 'toexecutelater'], (array)$params);
    }
} // end of toexecute class



