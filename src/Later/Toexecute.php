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
 */

namespace AA\Later;

use AA;
use AA\IO\DB\DB_AA;
use AA\Util\FatalLogger;
use AA\Util\FileLock;
use AA\Util\LogOnce;
use AA\Util\ReportTrait;
use AA_Log;
use AA_Logentry;
use AA_Logentry_X;
use AA_Logentry_Xrun;
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
class Toexecute
{
    use ReportTrait;

    public const TOEXECUTE_USER_TASK_TIME =  2000000000;

    /** Seconds a claimed task is leased - its execute_after is pushed this far
     *  ahead by the claim UPDATE in _exeOne(), so concurrent runners' scans do
     *  not see it while it executes. Must exceed the longest single task
     *  (executeTask() allows 360 s); if the claiming runner dies mid-task, the
     *  task retries after the lease expires. */
    public const CLAIM_LEASE = 1800;

    /** Task classes AA no longer has: their queued rows can never run and are removed. Only these -
     *  during a deploy or with a stale classmap a live class may be missing for a moment. */
    public const REMOVED_TASK_CLASSES = ['AA_Task_Import_Module_Chunk', 'AA\\Central\\Task\\ImportModuleChunk'];

    /** log.type of the removed-class tasks dropped from the queue */
    public const LOG_CLASS_GONE = 'TASK_GONE';

    /** log.type of the tasks left in the queue because their class is missing */
    public const LOG_CLASS_MISSING = 'TASK_MISS';

    /** log.type of a task that threw (log.type is 10 bytes); CheckQueue reports them */
    public const FAILED_EVENT = 'TOEXEC_ERR';

    /** later function
     *  Stores the object and params to the database for later execution.
     *  Such task is called from cron (the order depends on priority)
     *  selector is used for identifying class of task - used for deletion
     *  of duplicated task
     *
     * @param         $object
     * @param  array  $params
     * @param string  $selector
     * @param  int    $priority
     * @param         $time
     *
     * @return string|true
     * @example: we need to recount all links in all categories (Links module),
     *           so we need to cancel all older "recount" tasks, since it will
     *           be doubled in the queue (we call cancel_all() method for it)
     */
    public function later($object, $params = [], $selector = '', $priority = 100, $time = null): string|true
    {
        $varset = new Cvarset([
            ['created', time()],
            ['execute_after', ((int) $time < time() ? time() : $time)],  // task for user queue uses TOEXECUTE_USER_TASK_TIME
            ['aa_user', AuthGetUid()],
            ['priority', $priority],
            ['selector', ($selector ?: get_class_name($object, true))],
            ['object', serialize($object)],
            ['params', serialize($params)],
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
    public function laterOnce(object $object, array $params, string $selector, int $priority = 100, ?int $time = null, bool $just_once = false): void
    {
        static $scheduled = [];  // selector => true for tasks where $just_once is true

        if ($just_once) {
            if (isset($scheduled[$selector])) {
                return;
            }
            $scheduled[$selector] = true;
        }

        $time = (int) $time < time() ? time() : $time; // the same as in later()

        // The check+insert below is a race when two processes schedule the same
        // selector concurrently (both see "not scheduled", both insert - and the
        // duplicated task later executes twice). Serialize it with a DB named
        // lock, held on a dedicated connection so acquire and release pair up.
        // On lock timeout we proceed unlocked (scheduling must not be dropped).
        $lock_name = 'aa_laterOnce_' . constant('DB_NAME');
        $db = getDB();
        try {
            $db->query('SELECT GET_LOCK(' . $db->quote($lock_name) . ', 2)');
            $task = self::scheduledTask($selector);  // ['id' => $id, 'time' => $time]
            if (!$task['id']) {
                $this->later($object, $params, $selector, $priority, $time);
            } elseif ($task['time'] > $time) {
                // we need it run earlier
                DB_AA::update('toexecute', [['execute_after' => $time]], [['id', $task['id'], 'i']]);
            }
        } finally {
            $db->query('SELECT RELEASE_LOCK(' . $db->quote($lock_name) . ')');
            freeDB($db);
        }
    }

    /** @returns array scheduled ['id'=>.., 'time'=>..] or ['id'=>false, 'time'=>false]
     *  @usage  ['id' => $id, 'time' => $time] = self::scheduledTask($selector);
     */
    public static function scheduledTask($selector): array
    {
        $ret = DB_AA::select1([], "SELECT `id`,`execute_after` FROM `toexecute`", [['selector', $selector], ['priority', 0, '>']]);
        return $ret ?: ['id' => false, 'time' => false];
    }


    /** User task queue - we use it for splitting one long task (which would take
     *  ages) into subtasks, so it could be executed separately - one after
     *  the another. Such tasks are dedicated to logged user and are displayed
     *  for him/her in Item Manager.
     *  Such tasks are marked with execute_after=TOEXECUTE_USER_TASK_TIME in the
     *  toexecute table (which also means, that such task are never autoexecuted
     *  from the cron)
     */
    public function userQueue($object, $params, $selector, $priority = 100): void
    {
        $this->later($object, $params, $selector, $priority, self::TOEXECUTE_USER_TASK_TIME);
    }

    /** cancel_all function
     * @param $selector
     */
    public function cancel_all($selector): void
    {
        $varset = new Cvarset();
        $varset->doDeleteWhere('toexecute', "selector='" . quote($selector) . "'");
    }

    /** One queue run as the cron entry points do it: plan the AA maintenance
     *  tasks, then execute what is due. The single code path behind cron.php,
     *  misc/toexecute.php and CronFallback, single-flight on the fallback's
     *  lock file - OS cron, a second OS cron line and the fallback may arrive
     *  at once, and the CAS claim in _exeOne() should stay the safety net,
     *  not the rule.
     * @param string|null   $caller  script name logged as the TOEXECUTE start row; the
     *                               fallback passes null, so the start rows tell whether
     *                               OS cron is alive (CheckQueue)
     * @param callable|null $gate    evaluated under the lock; false = nothing to do
     *                               (the fallback's interval check)
     * @return bool false when another runner holds the lock or the gate said no
     */
    public static function runQueue(?string $caller = null, ?callable $gate = null): bool
    {
        $lock = new FileLock(CronFallback::lockFile());
        if (!$lock->Trylock()) {
            return false;
        }
        try {
            if ($gate !== null && !$gate()) {
                return false;
            }
            // queued objects of legacy classes are not autoloaded - unserialize() needs them declared
            require_once constant('AA_BASE_PATH') . 'include/mail.php';
            require_once constant('AA_BASE_PATH') . 'modules/links/cattree.php';

            if ($caller !== null) {
                AA_Log::write('TOEXECUTE', $caller, 'start');
            }
            // the lock file mtime is the fallback's suppression gate - touched before the
            // run too, so web traffic does not fire the fallback runner meanwhile
            touch(CronFallback::lockFile());
            (new ScheduleAaTasks())->planAaTasks();
            class_exists('AA_Perm');          // include/perm_core.php sets AA::$perm at file scope - loaded on demand
            AA::$perm->setPermMode('cron');   // tasks may edit under the ANONYMOUS_EDIT_CRON permission
            (new self())->execute();
            touch(CronFallback::lockFile());
            return true;
        } finally {
            $lock->Unlock();
        }
    }

    /** execute function - one run over the due tasks, see runQueue()
     */
    public function execute(): void
    {

        // MoreTimeLimit();  // try to set 5 minutes to run
        $allowed_time = (float) (defined('TOEXECUTE_ALLOWED_TIME') ? TOEXECUTE_ALLOWED_TIME : ((ini_get('max_execution_time') > 0) ? ini_get('max_execution_time') - 9 : 16.0));

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
            $ret = $this->_exeOne($task, $execute_times, $execute_start, $allowed_time);
            if ($ret > 0) {
                ++$count;
            } elseif ($ret === 0) {
                break;
            }
            // $ret < 0: a concurrent runner claimed the task, or its class is missing and
            // it stays queued - either way its execute_after moved ahead, so the next
            // SELECT skips it; just carry on
        }
        AA_Log::write('TOEXECUTE', 'AA\Later\Toexecute', new AA_Logentry_Xrun(['done' => $count, 'started' => $started, 'duration' => sprintf('%.3g', microtime(true) - $execute_start), 'allowed' => $allowed_time]));
    }

    /** Executes as many tasks from the $tasks array as time allows
     * @param $tasks    - array of ids of tasks to execute
     * @param $expected - optional task_id => priority the UI displayed when the
     *                    user selected the task; a task whose current priority
     *                    no longer matches is skipped (it was claimed/executed
     *                    meanwhile). Ids without expectation are not checked.
     */
    public function executeTask($tasks, array $expected = []): void
    {

        if (!is_array($tasks) || !count($tasks)) {
            return;
        }

        set_time_limit(360);   // try to set 360 seconds to run
        $allowed_time = (float) (defined('TOEXECUTE_ALLOWED_TIME') ? TOEXECUTE_ALLOWED_TIME : ((ini_get('max_execution_time') > 0) ? ini_get('max_execution_time') - 9 : 16.0));
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
            if (!$task) {
                $this->message("#$task_id: " . _m('task is no longer in the queue'));
                continue;   // task finished (deleted) meanwhile
            }
            if (isset($expected[$task_id]) && (int) $expected[$task_id] !== (int) $task['priority']) {
                $this->message("#$task_id: " . _m('skipped - the task changed meanwhile (reload the list)'));
                continue;   // claimed or executed since the UI list was rendered
            }
            // No lease-window check here, deliberately: this is the explicit
            // "Execute" action in the Task Manager UI - the user may re-run
            // even a task another runner is just executing (with a reloaded,
            // matching priority), and a task scheduled for the near future
            // must stay manually runnable. The CAS in _exeOne() still
            // collapses two concurrent clicks (same snapshot) into one run.
            ++$started;
            $ret = $this->_exeOne($task, $execute_times, $execute_start, $allowed_time);
            if ($ret > 0) {
                ++$count;
            } elseif ($ret === 0) {
                break;
            }
            // $ret < 0: claimed by a concurrent runner meanwhile, or its class is missing - skip it
        }
        AA_Log::write('TOEXECUTE', 'AA\Later\Toexecute', new AA_Logentry_Xrun(['done' => $count, 'started' => $started, 'duration' => sprintf('%.3g', microtime(true) - $execute_start), 'allowed' => $allowed_time]));
    }

    /** @return int 1 = task executed (or failed and left for a retry), 0 = out of
     *              allowed time (caller stops), -1 = lost the claim to a concurrent
     *              runner, or the task's class is missing and the task stays queued (caller skips) */
    public function _exeOne($task, &$execute_times, $execute_start, $allowed_time): int
    {
        $task_type = get_if($task['selector'], 'aa_unspecified');
        $expected_time = (float) get_if($execute_times[$task_type] ?? null, 1.0);  // default time expected for one task is 1 second
        $task_start = microtime(true);

        // can we run next task? Does it (most probably) fit in allowed_time?
        if ((($task_start + $expected_time) - $execute_start) > $allowed_time) {
            return 0;
        }
        // Atomic claim (CAS): in ONE conditional UPDATE push execute_after ahead
        // by the lease and lower the priority, guarded by the values we read.
        // Exactly one of the concurrent runners (OS-cron toexecute.php vs.
        // CronFallback) hits the row; the loser sees 0 affected rows and skips.
        // The lease hides the row from other runners' scans while it executes;
        // the post-execution DELETE removes it, and if this runner dies the task
        // retries after the lease expires. Lowering priority keeps the old
        // semantics: a repeatedly failing task sinks and priority=0 retires it.
        // User-queue tasks keep their parking time instead of a real lease -
        // if the runner dies mid-task they must stay invisible to the cron
        // scan (the user-queue contract), not surface 30 minutes later.
        $lease = ((int) $task['execute_after'] === self::TOEXECUTE_USER_TASK_TIME)
            ? self::TOEXECUTE_USER_TASK_TIME
            : time() + self::CLAIM_LEASE;
        $claimed = DB_AA::update('toexecute', [
            ['priority', max($task['priority'] - 1, 0), 'i'],
            ['execute_after', $lease, 'i'],
        ], [
            ['id', $task['id'], 'i'],
            ['execute_after', $task['execute_after'], 'i'],
            ['priority', $task['priority'], 'i'],
        ]);
        if ($claimed !== 1) {
            return -1;
        }

        try {
            $object = unserialize($task['object']);   // __wakeup() is task code too
            if ($object instanceof \__PHP_Incomplete_Class) {
                return $this->taskOfMissingClass($task, $object);
            }
            $params = unserialize((string) $task['params']);   // also fed to the log row below, so unserialize once
            if (is_object($object)) {
                $retcode = $this->execute_one($object, $params);
                $this->message($retcode);
                $selector = AA_Log::selector($object);
            } else {
                // id + class + size only - the serialized object may hold credentials
                $what = "#{$task['id']} " . (preg_match('/^[OC]:\d+:"([^"]+)"/', (string) $task['object'], $m) ? $m[1] : '?') . ' (' . strlen((string) $task['object']) . ' B)';
                $this->message('Toexecute:execute - unserialize err: ' . $what);
                $retcode = 'no execute, removed from queue';
                $selector = AA_Log::selector('no object', $what);
            }
        } catch (\Throwable $e) {
            // One broken task must not end the round for the tasks behind it. Its row
            // stays as the claim above left it - that is the queue's retry: again after
            // the lease, one priority lower, given up at 0. Never the exception message -
            // it may carry passwords or personal data.
            $execute_times[$task_type] = microtime(true) - $task_start;
            $this->message("#{$task['id']}: " . _m('the task failed with %1', [$e::class]));
            AA_Log::write(self::FAILED_EVENT, $task_type, self::failureParams((int) $task['id'], $e));
            return 1;
        }

        // Task is done - remove it from queue
        DB_AA::delete('toexecute', [['id', $task['id'], 'i']]);
        $execute_times[$task_type] = microtime(true) - $task_start;

        $mem_usage = sprintf('%.3g', (memory_get_usage() / (1024 * 1024)));
        $mem_peak  = sprintf('%.3g', (memory_get_peak_usage() / (1024 * 1024)));

        AA_Log::write('TOEXECUTE', $selector, AA_Logentry_X::make(sprintf('%.3g', $execute_times[$task_type]), $mem_usage, $mem_peak, (string) $retcode, $params));
        return 1;
    }

    /** A task whose class is not declared. Of a removed class it is dropped; of any other it stays
     *  queued - the claim's lease delays the retry and its lowered priority retires the row after about
     *  100 attempts, long enough to survive a deploy. The log gets one row a day per class, not one per
     *  task - there may be thousands of them (State Check: CheckQueuedClassMissing).
     *  @return int 1 = dropped, -1 = left in the queue (the caller goes on) */
    private function taskOfMissingClass(array $task, \__PHP_Incomplete_Class $object): int
    {
        $class = (string) (((array) $object)['__PHP_Incomplete_Class_Name'] ?? '');
        if (in_array($class, self::REMOVED_TASK_CLASSES, true)) {
            DB_AA::delete('toexecute', [['id', $task['id'], 'i']]);
            LogOnce::daily(self::LOG_CLASS_GONE, $class, (string) $task['selector']);
            $this->message("Toexecute: class $class no longer exists - task removed from the queue");
            return 1;
        }
        LogOnce::daily(self::LOG_CLASS_MISSING, $class, (string) $task['selector']);
        $this->message("Toexecute: class $class is missing - task left in the queue");
        return -1;
    }

    /** The FAILED_EVENT row: "#id Class file:line", never the message (it may carry
     *  passwords). The AA-relative path gives up its head first, so the file name and
     *  line survive the log.params width.
     */
    public static function failureParams(int $id, \Throwable $e): string
    {
        $head  = "#$id " . $e::class . ' ';
        $where = FatalLogger::format('', $e->getFile(), $e->getLine(), defined('AA_BASE_PATH') ? (string) constant('AA_BASE_PATH') : '')['selector'];
        // cut forward to a character boundary (drop UTF-8 continuation bytes); backing
        // up would overflow the column and the final cut would eat the line number
        $row   = $head . ltrim(substr($where, max(0, strlen($head) + strlen($where) - AA_Logentry::PARAMS_MAX)), "\x80..\xBF");
        return mb_strcut($row, 0, AA_Logentry::PARAMS_MAX);   // over only when the class name alone does not fit
    }

    /** execute_one function
     *
     * @param $object
     * @param $params
     *
     * @return string
     */
    public function execute_one($object, $params): string
    {
        if (!is_object($object) || $object instanceof \__PHP_Incomplete_Class) {
            return 'Toexecute:execute_one: No object: ' . serialize($object); // Error
        }
        set_time_limit(max(30, ini_get('max_execution_time')));   // 30 seconds (at least) for each task
        return $object->toexecutelater((array) $params);
    }
} // end of toexecute class
