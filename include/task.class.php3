<?php
/**
 * File contains definition of inputform class - used for displaying input form
 * for item add/edit and other form utility functions
 *
 * Should be included to other scripts (as /admin/itemedit.php3)
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


/** Task executed at planed time
 */
class AA_Plannedtask extends AA_Object {

    protected $task      = '';
    protected $condition = '';
    protected $event     = '';
    protected $time      = '';
    protected $shift     = 0;
    protected $item      = '';

    /** allows storing form in database
     *  AA_Object's method
     */
    static function getClassProperties() {
        return array (          //           id            name              type        multi  persist validator, required, help, morehelp, example
            'task'      => new AA_Property( 'task',      _m("Task"),         'text',    false, true, '', true),
            'condition' => new AA_Property( 'condition', _m("Condition"),    'text',    false, true, '', false, _m('If specified, the task is performed only if Condition is evaluated to value different from zero or empty value')),
            'event'     => new AA_Property( 'event',     _m("Event to run"), 'string',  true,  true, array('enum',array('ITEM_NEW'=> _m('New Item'), 'ITEM_UPDATED'=> _m('Item Updated'))), false,  _m('Event, when the task shoud be executed. It is independent on Time setting below (so you can run the task when Event occures and also on specific time). If you do not specify "+ seconds" parameter or set it to 0, then the task is executed directly. If the "+ seconds" offset is set, then the task is planed to execute once after x seconds.')),
            'time'      => new AA_Property( 'time',      _m("Time to run"),  'string',  false, true, '', false, _m('Specify the time, when the task shoud be executed. It will be then procesed periodicaly at this time. The specification of the time should be in "<a href="http://www.php.net/manual/en/datetime.formats.relative.php">Relative Format</a>", so the time like:<br>"midnight" - runs every midnight <br>"+1 hour" - runs every hour, <br>"+30 min" - runs every 30 minutes, <br>"16:00" - runs every day at 16:00<br>"Monday 10:00" - runs every Monday at 16:00<br>"first day of this month 10:00"<br>The times are not exact, the tasks are performed one after another by the script, which runs every 5 minutes, or so.')),
            'shift'     => new AA_Property( 'shift',     _m("+ seconds"),    'int',     false, true, '', false, _m('Optionaly specify the extra time offset added to previous time (in seconds).<br>It is hard to specify the "15-th in the month" by previous row, so you can combine both:<br> - "time" = "first day of this month 10:00"<br> - "+ seconds" = "1209600"<br> (60 seconds * 60 minutes * 24 hours * 14 days) - mention the 14 (1st + 14 = 15th)')),
            'item_id'   => new AA_Property( 'item_id',   _m("Item ID"),      'string',  false, true, 'id', false, _m('Optionaly specify the context - the long Item Id for which the task will be executed. You can then use {id..............} and other aliases of the item in the task. For "event" tasks (Item updated/new) is always filled with id of modified item.'))
            );
    }

    // static function factoryFromForm($oowner, $otype=null)        ... could be redefined here, but we use the standard one from AA_Object
    // static function getForm($oid=null, $owner=null, $otype=null) ... could be redefined here, but we use the standard one from AA_Object


    /** Manager top HTML  */
    protected static function getManagerTopHtml($fields) {
        return  _m('Current time on server'). ' '. date('Y-m-d H:i').
         '
          <table>
            <tr>
              <th>'.join("</th>\n<th>", array(  _m('Action'),_m('Name'), _m('Event'), _m('Time').'<br>'. _m('+ seconds'), _m('Task'), _m('Condition'), _m('ID'))).'</th>
            </tr>
            ';
    }

    /** Manager row HTML  */
   protected static function getManagerRowHtml($fields, $aliases, $links) {
      // huhl($aliases);exit;
       return '
           <tr>
             <td>'. a_href($links['Edit'], _m('Edit'), 'aa-button-edit').' '. a_href($links['Delete'], _m('Delete'), 'aa-button-delete'). '</td>
             <td>_#AA_NAME_</td>
             <td>_#EVENT___</td>
             <td>_#TIME____<br>{ifeq:{_#SHIFT___}:::0::+_#1s}</td>
             <td>{expandable:{_#TASK____}:30:...:&raquo;:&laquo;}</td>
             <td>{expandable:{_#CONDITIO}:30:...:&raquo;:&laquo;}</td>
             <td><small>_#AA_ID___</small></td>
           </tr>
           ';
   }

    function nexttime() {
        // every 5 min
        if ( !strlen($time = trim($this->getProperty('time'))) ) {
            return 0;
        }
        return strtotime($this->getProperty('time')) + (int)$this->getProperty('shift');
    }

    function toexecutelater() {
        $condition = trim($this->condition);
        if (strlen($condition)) {
            $condition_res = trim(AA_Stringexpand::unalias($condition));
            if (!strlen($condition_res) OR ((string)$condition_res==='0')) {
                return;
            }
        }
        AA_Stringexpand::unalias($this->task, '', is_long_id($this->item_id) ? AA_Items::getItem(new zids($this->item_id, 'l')) : null);
    }

    /** method called after save */
    function aftersave() {
        $toexecute = new AA_Toexecute;
        $toexecute->cancel_all('Plannedtask_'. $this->getId());
        $this->schedule();
    }

    /** check if the task is scheduled and if not - schedule it for future execution */
    function schedule($force_time = null) {
        $time = is_null($force_time) ? $this->nexttime() : $force_time;
        if ($time >= time()) {
            $toexecute = new AA_Toexecute;
            $toexecute->laterOnce($this, array(), 'Plannedtask_'. $this->getId(). (is_long_id($this->item_id) ? ('_'.$this->item_id) : ''), 100, $time);
        }
    }

    static function getForm($oid=null, $owner=null, $otype=null) {
        $form  = parent::getForm($oid, $owner, $otype);
        $next_time      = DB_AA::select1("SELECT execute_after FROM `toexecute`", 'execute_after', array(array('selector',"Plannedtask_$oid")));
        $next_time      = $next_time ? date('Y-m-d H:i',$next_time) : _m('not scheduled, yet');
        // $last_execution = DB_AA::select1("SELECT time FROM `log`", 'time', array(array('type','TOEXECUTE'),array('selector',"Plannedtask_$oid")));
        // $last_execution = $last_execution ? date('Y-m-d H:i',$last_execution) : _m('no log entry, yet');

        $form->addRow(new AA_Formrow_Text(_m('next run'). ": $next_time" ));
        // $form->addRow(new AA_Formrow_Text(_m('last execution'). ": $last_execution" ));
        return $form;
    }

    static public function executeForEvent($module_id, $event_id, $item_id ) {
        $aa_set = new AA_Set();
        $aa_set->setModules($module_id);
        $aa_set->addCondition(new AA_Condition('event', '=', $event_id));

        $zids  = AA_Object::querySet('AA_Plannedtask', $aa_set);

        $GLOBALS['ddd'] = true;

        foreach ($zids as $id) {
            $task = AA_Object::load($id, 'AA_Plannedtask');
            if (is_long_id($item_id)) {
                $task->setProperty('item_id', $item_id);
            }
            if ( ($shift = (int)$task->getProperty('shift')) == 0) {
                $task->toexecutelater();
            } else {
                $task->schedule(time()+$shift);
            }
        }
    }
}



/** Check all AA_Planedtask and schedule it for execution, if not scheduled
 */
class AA_Plannedtask_Schedule {

    function toexecutelater() {

        $aa_set = new AA_Set();
        //$aa_set->setModules($module_id);
        //$aa_set->addCondition(new AA_Condition('time', 'NOTNULL', 1));

        $zids  = AA_Object::querySet('AA_Plannedtask', $aa_set);

        foreach ($zids as $id) {
            $task = AA_Object::load($id, 'AA_Plannedtask');
            $task->schedule();
        }
    }
}



?>
