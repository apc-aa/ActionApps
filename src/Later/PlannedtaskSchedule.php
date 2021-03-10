<?php

namespace AA\Later;

use AA_Object;
use AA_Set;

/** Check all AA_Plannedtask and schedule it for execution, if not scheduled
 */
class PlannedtaskSchedule implements LaterInterface
{

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     * @param array $params - numeric array of additional parameters for the execution passed in time of call
     * @return string - message about execution to be logged
     * @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = [])
    {

        $ret = '';
        $aa_set = new AA_Set();
        //$aa_set->setModules($module_id);
        //$aa_set->addCondition(new AA_Condition('time', 'NOTNULL', 1));

        $zids = AA_Object::querySet('AA_Plannedtask', $aa_set);

        $ret .= $zids->count() . ';';

        foreach ($zids as $id) {
            $ret .= $id . ';';
            $task = AA_Object::load($id, 'AA_Plannedtask');
            $ret .= get_class($task) . ';';
            $ret .= $task->schedule() . ';';
        }
        return $ret;
    }
}