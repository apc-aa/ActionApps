<?php

/**
 * File contains definition of inputform class - used for displaying input form
 * for item add/edit and other form utility functions
 *
 * Should be included to other scripts (as /admin/itemedit.php)
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

use AA\IO\DB\DB_AA;
use AA\Later\LaterInterface;
use AA\Later\Toexecute;

/** Task executed at planed time
 */
class AA_Plannedtask extends AA_Object implements LaterInterface, AA\Util\NamedInterface, AA\Expand\InternalExpandInterface
{
    protected $task = '';
    protected $condition = '';
    protected $event = '';
    protected $time = '';
    protected $shift = 0;
    protected $item_id = '';

    /** the task whose toexecutelater() runs now - an expression may act only inside a task */
    private static ?self $running = null;

    /** allows storing form in database
     *  AA_Object's method
     * @return array
     */
    public static function getClassProperties(): array
    {
        return [          //           id            name              type        multi  persist validator, required, help, morehelp, example
            'task' => new AA_Property('task', _m("Task"), 'text', false, true, '', true),
            'condition' => new AA_Property('condition', _m("Condition"), 'text', false, true, '', false, _m('If specified, the task is performed only if Condition is evaluated to value different from zero or empty value')),
            'event' => new AA_Property('event', _m("Event to run"), 'string', true, true, [
                'enum',
                ['ITEM_NEW' => _m('New Item'), 'ITEM_UPDATED' => _m('Item Updated')],
            ], false, _m('Event, when the task should be executed. It is independent on Time setting below (so you can run the task when Event occures and also on specific time). If you do not specify "+ seconds" parameter or set it to 0, then the task is executed directly. If the "+ seconds" offset is set, then the task is planed to execute once after x seconds.')),
            'time' => new AA_Property('time', _m("Time to run"), 'string', false, true, '', false, _m("Specify the time, when the task should be executed. It will be then processed periodically at this time. The specification of the time should be in \"<a href=\"https://www.php.net/manual/en/datetime.formats.relative.php\">Relative Format</a>\", so the time like:<br>\"midnight\" - runs every midnight <br>\"+1 hour\" - runs every hour, <br>\"+30 min\" - runs every 30 minutes, <br>\"16:00\" - runs every day at 16:00<br>\"Monday 10:00\" - runs every Monday at 16:00<br>\"first day of this month 10:00\"<br>The times are not exact, the tasks are performed one after another by the script, which runs every 5 minutes, or so.")),
            'shift' => new AA_Property('shift', _m("+ seconds"), 'int', false, true, '', false, _m('Optionally specify the extra time offset added to previous time (in seconds).<br>It is hard to specify the "15-th in the month" by previous row, so you can combine both:<br> - "time" = "first day of this month 10:00"<br> - "+ seconds" = "1209600"<br> (60 seconds * 60 minutes * 24 hours * 14 days) - mention the 14 (1st + 14 = 15th)')),
            'item_id' => new AA_Property('item_id', _m("Item ID"), 'string', false, true, 'id', false, _m("Optionally specify the context - the long Item Id for which the task will be executed. You can then use {id..............} and other aliases of the item in the task. The id of item could be also obtained in variable {var:aa_event_for}. For \"event\" tasks (Item updated/new) it is always filled with id of modified item.")),
        ];
    }

    // static function factoryFromForm($oowner, $otype=null)        ... could be redefined here, but we use the standard one from AA_Object
    // static function getForm($oid=null, $owner=null, $otype=null) ... could be redefined here, but we use the standard one from AA_Object


    /**
     * @inheritDoc
     * needed as title to Alias manager page /modules/site/aliases.php
     */
    public static function name(): string
    {
        return _m('Planned tasks');
    }

    /**
     * @inheritDoc
     */
    public static function description(): string
    {
        return '';
    }

    /** @inheritDoc */
    protected static function getAdminUrlTemplate(): string
    {
        return get_admin_url('se_tasks.php', ['id=_#AA_ID___','module_id=_#AA_OWNER']);
    }

    /** Manager top HTML  */
    protected static function getManagerTopHtml($fields): string
    {
        return _m('Current time on server') . ' ' . date('Y-m-d H:i')
            . '
          <table>
            <thead>
            <tr>
              <th>' . implode("</th>\n<th scope=col>", ['', _m('Action'), _m('Name'), _m('Event'), _m('Time') . '<br>' . _m('+ seconds'), _m('Computed time'), _m('Condition'), _m('Scheduled to'), _m('Task'), _m('ID'), _m('Module')]) . '</th>
            </tr>
            </thead>
            <tbody>
            ';
    }

    /** Manager row HTML  */
    protected static function getManagerRowHtml($fields, $aliases, $buttons): string
    {
        return '
            <tr>
              <td><input name="chb[x_#AA_ID___]" value="" type="checkbox"></td>
              <td style="white-space:nowrap;">'
            . $buttons['Edit'] . ' '
            . ($buttons['Runnow'] ?? '') . ' '
            /* `RLIKE` (= "begins with" -> `selector LIKE 'AA_Plannedtask:{id}%'`) so the
               button finds both the rows written before AA_Logentry_Obj
               (`AA_Plannedtask:{id}`) and the ones written since
               (`AA_Plannedtask:{id}:Obj`). A prefix, not a substring: it cannot match
               an id that merely appears somewhere inside another selector, and it is
               the only form a `selector` index could ever serve. */
            . a_href(AA_Log::getAdminUrl('', 'AA_Plannedtask:_#AA_ID___', 'RLIKE'), _m('Log'), ['class' => 'aa-button-show']) . ' '
            . $buttons['DeleteObject'] . ' </td>
              <td>_#AA_NAME_</td>
              <td>_#EVENT___</td>
              <td>_#TIME____<br>{ifeq:{_#SHIFT___}:::0::+_#1s}</td>
              <td>{internal:' . self::class . ':computedtime}</td>
              <td>{expandable:{_#CONDITIO}:30:...:&raquo;:&laquo;}</td>
              <td>{internal:' . self::class . ':scheduledto}</td>
              <td>{expandable:{_#TASK____}:30:...:&raquo;:&laquo;}</td>
              <td><small>_#AA_ID___</small></td>
              <td><small title="_#AA_OWNER">_#AA_OW_NM</small></td>
            </tr>
            ';
    }

    /** the time the task names, read at $now (default the current time); 0 = it names none */
    public function nexttime(?int $now = null): int
    {
        // every 5 min
        if (!strlen($time = trim($this->getProperty('time')))) {
            return 0;
        }
        return (int) strtotime($time, $now ?? time()) + (int) $this->getProperty('shift');
    }

    /** When the task is queued to run next, at $now or later. schedule() queues only a time not yet
     *  past, so a time past today (03:00 at noon) comes on a later day - read here at the start of
     *  each day, as the scheduler reads it on its rounds. 0 = never within a year. */
    public function nextStart(int $now): int
    {
        if (!strlen(trim($this->getProperty('time')))) {
            return 0;
        }
        for ($day = 0; $day <= 366; ++$day) {
            $base = ($day === 0) ? $now : (int) strtotime("today +$day days", $now);
            $at   = $this->nexttime($base);
            if ($at >= $base) {
                return $at;
            }
        }
        return 0;
    }

    /** Implement internal aliases for Admin interface Such aliases then could be called as {internal:AA_Plannedtask:lastrun:...},
     * @implements InternalExpandInterface::internal_expand()
     */
    public static function internal_expand(?AA_Content $content, ...$params): string
    {
        [$info] = $params;
        if ($task_id = $content?->getId()) {
            switch ($info) {
                case 'scheduledto':
                    $task = Toexecute::scheduledTask(self::toexecuteSelector($task_id));
                    return '<span title="task.id:' . $task['id'] . '">' . ($task['execute_after'] ? date('Y-m-d H:i:s', $task['execute_after']) : '--') . '</span>';
                case 'computedtime':
                    $task = AA_Object::load($task_id, 'AA_Plannedtask');
                    $time = $task?->nexttime();
                    return $time ? date('Y-m-d H:i:s', $time) : '--';
            }
        }
        return '';
    }

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = []): string
    {
        // a task run by an event inside another task hands the context back when it ends
        $outer = self::$running;
        self::$running = $this;
        try {
            $ret = $this->aa_name;
            AA::$slice_id = $this->getOwnerId();
            if (is_long_id($this->item_id)) {
                AA::Un('{define:aa_event_for:' . $this->item_id . '}');
            }
            $condition = trim($this->condition);
            $condition_res = strlen($condition) ? trim(AA::Un($condition)) : '1';
            if (!strlen($condition_res) || ($condition_res === '0')) {
                $ret .= ":Cond not met - $condition ($condition_res)";
            } else {
                if (is_long_id($this->item_id)) {
                    $ret .= ':=' . AA::Un($this->task, '', AA_Items::getItem(new zids($this->item_id, 'l')));
                } else {
                    $ret .= ':=' . AA::Un($this->task);
                }
            }
        } finally {
            self::$running = $outer;
        }
        // AA_Log::write('TOEXECUTE', get_class($this) . ':' . $this->getId(), 'end' . ":$ret:x".AA::$slice_id);  // logged in Toexecute
        return $ret;
    }

    /** The task whose toexecutelater() runs now - from the queue, an event or "Run now"; null outside a task */
    public static function running(): ?self
    {
        return self::$running;
    }

    /** Whether the task or its condition calls {import} - the admin then asks for the feeding permission.
     *  A safeguard against a slip in the admin, not a permission boundary: an expression can put the
     *  command together only when it runs. Command names are case-insensitive. */
    public function usesImport(): bool
    {
        return (bool) self::importCalls($this->task . "\n" . $this->condition);
    }

    /** The first argument of each {import} call in $text - {import:<arg>:..}, {import(<arg>)}, or ''
     *  for a bare {import}; the import's name or id.
     *  @return list<string> */
    public static function importCalls(string $text): array
    {
        return array_column(self::importCallArgs($text), 0);
    }

    /** importCalls() with the mode of each call as written ('' = none; ending in '{' = an expression
     *  sets it) and the delimiter after the command name: ':', '(' or '' for a bare {import}
     *  @return list<array{string, string, string}> */
    public static function importCallArgs(string $text): array
    {
        // the '{' of an expression in the mode is only looked at - it may open another {import}
        preg_match_all('/\{import(?:\(([^)}]*)\)?|:([^:}]*)(?::([^:{}]*)(?:(?=(\{))|))?|(?=\}))/i', $text, $calls, PREG_SET_ORDER);
        return array_map(static fn(array $call): array => [($call[1] ?? '') . ($call[2] ?? ''), ($call[3] ?? '') . ($call[4] ?? ''), substr($call[0], 7, 1)], $calls);
    }

    /** The page of the tasks also lets in users who only edit the forms, but an import belongs to
     *  the users who manage the feeding of the slice. */
    public function formRefusal(): ?string
    {
        if ($this->usesImport() && !IfSlPerm(PS_FEEDING, $this->getOwnerId())) {
            return _m('The task was not saved - only a user who manages the feeding of the slice may save a task with {import}.');
        }
        return null;
    }

    /** A link may open a new task with a name, a time and a plain {import} call - another
     *  expression from a link is dropped, the user would save it without reading it.
     *  @inheritDoc
     */
    public static function formPrefill(mixed $requested): array
    {
        if (!is_array($requested)) {
            return [];
        }
        $valid = [
            // the name is shown in lists - no markup, no expression
            'aa_name' => static fn(string $v): bool => (bool) preg_match('/\A[^<>&"{}\x00-\x1f]{1,100}\z/u', $v),
            'task'    => static fn(string $v): bool => (bool) preg_match('/\A\{import:[0-9a-f]{32}:(?:run|changed|check)\}\z/', $v),
            // strtotime() reads only up to a NUL byte, so control characters are refused first
            'time'    => static fn(string $v): bool => !preg_match('/[\x00-\x1f]/', $v) && (strtotime($v) !== false),
        ];
        $prefill = [];
        foreach ($valid as $key => $is_valid) {
            if (is_string($requested[$key] ?? null) && $is_valid($requested[$key])) {
                $prefill[$key] = $requested[$key];
            }
        }
        return $prefill;
    }

    /**
     * @param string $id
     * @return string
     */
    public static function toexecuteSelector($id): string
    {
        return "Plannedtask_$id";
    }

    /** method called after save */
    public function aftersave(): void
    {
        $toexecute = new Toexecute();
        $toexecute->cancel_all(self::toexecuteSelector($this->getId()));
        // a waiting "Run now" holds a copy of the task as it was - the old version must not run
        $toexecute->cancel_all(self::toexecuteSelector($this->getId()) . ':now');
        $this->schedule();
    }

    /** check if the task is scheduled and if not - schedule it for future execution */
    public function schedule($force_time = null): string
    {
        $time = is_null($force_time) ? $this->nexttime() : $force_time;
        if ($time >= time()) {
            $toexecute = new Toexecute();
            $toexecute->laterOnce($this, [], self::toexecuteSelector($this->getId()), 100, $time);
        }
        return "$time>=" . time() . ' ';
    }

    public static function getForm($oid = null, $owner = null, $otype = null)
    {
        $form = parent::getForm($oid, $owner, $otype);
        $next_time = DB_AA::select1('execute_after', "SELECT execute_after FROM `toexecute`", [['selector', "Plannedtask_$oid"]]);
        $next_time = $next_time ? date('Y-m-d H:i', $next_time) : _m('not scheduled, yet');
        // $last_execution = DB_AA::select1("SELECT time FROM `log`", 'time', array(array('type','TOEXECUTE'),array('selector',"Plannedtask_$oid")));
        // $last_execution = $last_execution ? date('Y-m-d H:i',$last_execution) : _m('no log entry, yet');

        $form->addRow(new AA_Formrow_Text(_m('next run') . ": $next_time"));
        // $form->addRow(new AA_Formrow_Text(_m('last execution'). ": $last_execution" ));
        return $form;
    }

    public static function executeForEvent(string $module_id, string $event_id, string $item_id): void
    {
        $tasks = AA::Contentcache()->get_result(['AA_Plannedtask', 'getModuleTasks'], [$module_id, $event_id], '', true);
        foreach ($tasks as $task) {
            if (is_long_id($item_id)) {
                $task->setProperty('item_id', $item_id);
            }
            if (($shift = (int) $task->getProperty('shift')) === 0) {
                $task->toexecutelater();
            } else {
                $task->schedule(time() + $shift);
            }
        }
    }

    /** get tasks for module and event (used mainly for caching - when moved multiple items to trash...))
     * @param string $module_id
     * @param string $event_id
     * @return AA_Plannedtask[]
     */
    public static function getModuleTasks($module_id, $event_id): array
    {
        $aa_set = new AA_Set();
        $aa_set->setModules([$module_id]);
        $aa_set->addCondition(new AA_Condition('event', '==', $event_id));

        $zids = AA_Object::querySet('AA_Plannedtask', $aa_set);
        $tasks = [];

        foreach ($zids as $id) {
            $task = AA_Object::load($id, 'AA_Plannedtask');
            if ($task instanceof self) {
                $tasks[] = $task;
            }
        }
        return $tasks;
    }

}
