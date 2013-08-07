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

    protected $task = '';

    /** allows storing form in database
     *  AA_Object's method
     */
    function getClassProperties() {
        return array (          //           id       name       type        multi  persist validator, required, help, morehelp, example
            'task'    => new AA_Property( 'task', _m("Task"),         'text', false, true, '', true),
            'time'    => new AA_Property( 'time', _m("Time to run"),  'string', false, true, '', true, _m('Specify the time, when the task shoud be executed. It will be then procesed periodicaly at this time. The specification of the time should be in "<a href="http://www.php.net/manual/en/datetime.formats.relative.php">Relative Format</a>", so the time like:<br>"midnight" - runs every midnight <br>"+1 hour" - runs every hour, <br>"+30 min" - runs every 30 minutes, <br>"16:00" - runs every day at 16:00<br>The times are not exact, the tasks are performed one after another by the script, which runs every 5 minutes, or so.'))
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
              <th width="30">&nbsp;</th>
              <th>'.join("</th>\n<th>", array( _m('Name'), _m('Time'), _m('Task'), _m('ID'))).'</th>
            </tr>
            ';
    }

    /** Manager row HTML  */
   protected static function getManagerRowHtml($fields, $aliases, $links) {
      // huhl($aliases);exit;
       return '
           <tr>
             <td><input type="checkbox" name="chb[x_#AA_ID___]" value=""></td>
             <td>'. a_href($links['Edit'], '_#AA_NAME_'). '</td>
             <td>_#TIME____</td>
             <td>_#TASK____</td>
             <td>_#AA_ID___</td>
           </tr>
           ';
   }

    function nexttime() {
        // every 5 min
        return strtotime($this->getProperty('time'));
    }

    function toexecutelater() {
        AA_Stringexpand::unalias($this->task);
    }

    /** method called after save */
    function aftersave() {
        $toexecute = new AA_Toexecute;
        $toexecute->cancel_all('Plannedtask_'. $this->getId());
        $this->schedule();
    }

    /** check if the task is scheduled and if not - schedule it for future execution */
    function schedule() {
        $time = $this->nexttime();
        if ($time >= time()) {
            $toexecute = new AA_Toexecute;
            $toexecute->laterOnce($this, array(), 'Plannedtask_'. $this->getId(), 100, $time);
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
}



/** Check all AA_Planedtask and schedule it for execution, if not scheduled
 */
class AA_Plannedtask_Schedule {

    function toexecutelater() {

        $aa_set = new AA_Set();
        //$aa_set->setModules($module_id);
        //$aa_set->addCondition(new AA_Condition('aa_user',       '=', $auth->auth['uid']));

        $zids  = AA_Object::querySet('AA_Plannedtask', $aa_set);

        foreach ($zids as $id) {
            $task = AA_Object::load($id, 'AA_Plannedtask');
            $task->schedule();
        }
    }
}



?>
