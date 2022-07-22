<?php
/**
 * File contains definition of inputform class - used for displaying input form
 * for item add/edit and other form utility functions
 *
 * Should be included to other scripts (as /admin/itemedit.php3)
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


use AA;
use AA\IO\DB\DB_AA;
use AA\Later\LaterInterface;
use AA\Later\Toexecute;
use AA_Condition;
use AA_Formrow_Text;
use AA_Items;
use AA_Log;
use AA_Object;
use AA_Property;
use AA_Set;
use zids;


/** Task executed at planed time
 */
class AA_Plannedtask extends AA_Object implements LaterInterface
{

    protected $task = '';
    protected $condition = '';
    protected $event = '';
    protected $time = '';
    protected $shift = 0;
    protected $item_id = '';

    /** allows storing form in database
     *  AA_Object's method
     * @return array
     */
    static function getClassProperties(): array
    {
        return [          //           id            name              type        multi  persist validator, required, help, morehelp, example
            'task' => new AA_Property('task', _m("Task"), 'text', false, true, '', true),
            'condition' => new AA_Property('condition', _m("Condition"), 'text', false, true, '', false, _m('If specified, the task is performed only if Condition is evaluated to value different from zero or empty value')),
            'event' => new AA_Property('event', _m("Event to run"), 'string', true, true, [
                'enum',
                ['ITEM_NEW' => _m('New Item'), 'ITEM_UPDATED' => _m('Item Updated')]
            ], false, _m('Event, when the task shoud be executed. It is independent on Time setting below (so you can run the task when Event occures and also on specific time). If you do not specify "+ seconds" parameter or set it to 0, then the task is executed directly. If the "+ seconds" offset is set, then the task is planed to execute once after x seconds.')),
            'time' => new AA_Property('time', _m("Time to run"), 'string', false, true, '', false, _m('Specify the time, when the task shoud be executed. It will be then procesed periodicaly at this time. The specification of the time should be in "<a href="https://www.php.net/manual/en/datetime.formats.relative.php">Relative Format</a>", so the time like:<br>"midnight" - runs every midnight <br>"+1 hour" - runs every hour, <br>"+30 min" - runs every 30 minutes, <br>"16:00" - runs every day at 16:00<br>"Monday 10:00" - runs every Monday at 16:00<br>"first day of this month 10:00"<br>The times are not exact, the tasks are performed one after another by the script, which runs every 5 minutes, or so.')),
            'shift' => new AA_Property('shift', _m("+ seconds"), 'int', false, true, '', false, _m('Optionally specify the extra time offset added to previous time (in seconds).<br>It is hard to specify the "15-th in the month" by previous row, so you can combine both:<br> - "time" = "first day of this month 10:00"<br> - "+ seconds" = "1209600"<br> (60 seconds * 60 minutes * 24 hours * 14 days) - mention the 14 (1st + 14 = 15th)')),
            'item_id' => new AA_Property('item_id', _m("Item ID"), 'string', false, true, 'id', false, _m('Optionally specify the context - the long Item Id for which the task will be executed. You can then use {id..............} and other aliases of the item in the task. The id of item could be also obtained in variable {var:aa_event_for}. For "event" tasks (Item updated/new) is always filled with id of modified item.'))
        ];
    }

    // static function factoryFromForm($oowner, $otype=null)        ... could be redefined here, but we use the standard one from AA_Object
    // static function getForm($oid=null, $owner=null, $otype=null) ... could be redefined here, but we use the standard one from AA_Object


    /** Manager top HTML  */
    protected static function getManagerTopHtml($fields)
    {
        return _m('Current time on server') . ' ' . date('Y-m-d H:i') .
            '
          <table>
            <tr>
              <th>' . join("</th>\n<th>", [_m('Action'), _m('Name'), _m('Event'), _m('Time') . '<br>' . _m('+ seconds'), _m('Computed time'), _m('Condition'), _m('Scheduled to'), _m('Task'), _m('ID'), _m('Module')]) . '</th>
            </tr>
            ';
    }

    /** Manager row HTML  */
    protected static function getManagerRowHtml($fields, $aliases, $links)
    {
        return '
            <tr>
              <td style="white-space:nowrap;">' .
            a_href($links['Edit'], _m('Edit'), 'aa-button-edit') . ' ' .
            a_href(get_admin_url('aa_log.php?&srchbr_akce=1&srchbr_field%5B0%5D=selector&srchbr_oper%5B0%5D==&srchbr_value%5B0%5D=AA_Plannedtask:_#AA_ID___&srchbr_order%5B0%5D=time&srchbr_order_dir%5B0%5D=on'), _m('Log'), 'aa-button-show') . ' ' .
            a_href($links['Delete'], _m('Delete'), 'aa-button-delete') . '</td>
              <td>_#AA_NAME_</td>
              <td>_#EVENT___</td>
              <td>_#TIME____<br>{ifeq:{_#SHIFT___}:::0::+_#1s}</td>
              <td>{internal:' . get_class() . ':computedtime}</td>
              <td>{expandable:{_#CONDITIO}:30:...:&raquo;:&laquo;}</td>
              <td>{internal:' . get_class() . ':scheduledto}</td>
              <td>{expandable:{_#TASK____}:30:...:&raquo;:&laquo;}</td>
              <td><small>_#AA_ID___</small></td>
              <td><small title="_#AA_OWNER">_#AA_OW_NM</small></td>
            </tr>
            ';
    }

    function nexttime()
    {
        // every 5 min
        if (!strlen($time = trim($this->getProperty('time')))) {
            return 0;
        }
        return strtotime($time) + (int)$this->getProperty('shift');
    }

    /** The possibility, how to implement internal aliases for Admin interface
     *  Such aliases then could be called as {internal:AA_Plannedtask:lastrun:...},
     *  where AA_Plannedtask is AA class, which has internal_expand() method.
     *  Such function should never leak potentially sensitive data, since
     *  anyone could call it (although we will try to do it callable just form
     *  internal AA admin interface). It should not be called outside and
     *  results are not guaranteed.
     */
    static function internal_expand($content, $info)
    {
        // $params  = func_get_args();
        $task_id = $content->getId();
        switch ($info) {
            case 'scheduledto':
                $time = Toexecute::scheduledTime(self::toexecuteSelector($task_id));
                return '<span title="task.id:' . Toexecute::scheduledTaskId(self::toexecuteSelector($task_id)) . '">' . ($time ? date('Y-m-d H:i:s', $time) : '--') . '</span>';
            case 'computedtime':
                $task = AA_Object::load($task_id, 'AA_Plannedtask');
                $time = $task->nexttime();
                return $time ? date('Y-m-d H:i:s', $time) : '--';
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
        $ret = $this->aa_name;
        AA::$slice_id = $this->getOwnerId();
        if (is_long_id($this->item_id)) {
            AA::Stringexpander()->unalias('{define:aa_event_for:' . $this->item_id . '}');
        }
        $condition = trim($this->condition);
        $condition_res = strlen($condition) ? trim(AA::Stringexpander()->unalias($condition)) : '1';
        if (!strlen($condition_res) or ((string)$condition_res === '0')) {
            $ret .= ":Cond not met - $condition ($condition_res)";
        } else {
            if (is_long_id($this->item_id)) {
                $ret .= ':=' . AA::Stringexpander()->unalias($this->task, '', AA_Items::getItem(new zids($this->item_id, 'l')));
            } else {
                $ret .= ':=' . AA::Stringexpander()->unalias($this->task);
            }
        }
        AA_Log::write('TOEXECUTE', get_class($this) . ':' . $this->getId(), 'end' . ":$ret:");
        return $ret;
    }

    /**
     * @param string $id
     * @return string
     */
    static function toexecuteSelector($id): string
    {
        return "Plannedtask_$id";
    }

    /** method called after save */
    function aftersave()
    {
        $toexecute = new Toexecute;
        $toexecute->cancel_all(self::toexecuteSelector($this->getId()));
        $this->schedule();
    }

    /** check if the task is scheduled and if not - schedule it for future execution */
    function schedule($force_time = null)
    {
        $time = is_null($force_time) ? $this->nexttime() : $force_time;
        if ($time >= time()) {
            $toexecute = new Toexecute;
            $toexecute->laterOnce($this, [], self::toexecuteSelector($this->getId()), 100, $time);
        }
        return "$time>=" . time() . ' ';
    }

    static function getForm($oid = null, $owner = null, $otype = null)
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

    static public function executeForEvent($module_id, $event_id, $item_id)
    {
        $aa_set = new AA_Set();
        $aa_set->setModules($module_id);
        $aa_set->addCondition(new AA_Condition('event', '==', $event_id));

        $zids = AA_Object::querySet('AA_Plannedtask', $aa_set);

        foreach ($zids as $id) {
            $task = AA_Object::load($id, 'AA_Plannedtask');
            if (is_long_id($item_id)) {
                $task->setProperty('item_id', $item_id);
            }
            if (($shift = (int)$task->getProperty('shift')) == 0) {
                $task->toexecutelater();
            } else {
                $task->schedule(time() + $shift);
            }
        }
    }
}




