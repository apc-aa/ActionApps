<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 6.6.19
 * Time: 10:50
 */

namespace AA\Util;

use AA;
use AA\IO\DB\DB_AA;
use AA_GeneralizedArray;
use AA_Set;
use Cvarset;
use zids;

class ChangesMonitor {

    /** singleton
     *  This function makes sure, there is just ONE static instance if the class
     *  (this is better implementation, than static class variable - will work with subclasses)
     *   !!DO NOT use AA_Singleton - util may not be included
     */
    public static function singleton(): ChangesMonitor {
        static $instance = null;
        if (is_null($instance)) {
            $instance = new static();
        }
        return $instance;
    }

    /** addProposal function
     * @param $change_proposals
     * @return bool
     */
    function addProposal(array $change_proposals): bool
    {
        return $this->_add($change_proposals, 'p');
    }

    /** addHistory function
     * @param ChangeProposal[] $change_proposals
     * @return bool
     */
    function addHistory(array $change_proposals): bool
    {
        return $this->_add($change_proposals, 'h');
    }

    /** Prepares AA\Util\ChangeProposal array from old values and new values
     *  Usage: AA\Util\ChangesMonitor::singleton()->addHistory(AA\Util\ChangesMonitor::getDiff($id, $old, $new));
     * @param string $id
     * @param array $old
     * @param array $new
     * @return ChangeProposal[]
     * @see \AA_Content::diff() - we use it for similar purpose. @todo Can we use the same? HM 2021-02
     */
    static function getDiff($id, $old, $new): array
    {
        $changes = [];
        if (is_array($old) AND is_array($new)) {
            foreach ($new as $fid => $a) {
                if ($old[$fid] != $a) {
                    $changes[] = new ChangeProposal($id, $fid, [$old[$fid]]);
                }
            }
        }
        return $changes;
    }

    /** url to history table in AA Admin interface
     * @param      $resource_id
     * @return string url to history table in AA Admin interface
     */
    static function getHistoryUrl($resource_id) {
        return get_admin_url('aafinder.php', ['showhistory' => $resource_id]);
    }

    /** link to history table in AA Admin interface
     * @param      $resource_id
     * @return string url to history table in AA Admin interface
     */
    static function getHistoryLink($resource_id) {
        $history_entries = self::getChangeList($resource_id);
        return $history_entries ? a_href(self::getHistoryUrl($resource_id), _m('history (%1)', [count($history_entries)])) : '';
    }

    /** _add function
     * @param ChangeProposal[] $change_proposals
     * @param string $type 'h'|'p'  (= history or proposal)
     * @return bool
     */
    function _add(array $change_proposals, $type): bool
    {
        if (!is_object($change = reset($change_proposals))) {
            return false;
        }

        $change_id = new_id();
        $varset = new Cvarset;
        $varset->addkey("id", "text", $change_id);
        //$varset->add("time", "number", now());
        // changed to nanotime(), which produces 1 000 000 000 * bigger values, but it should be OK for database
        // there was problem with now() - some history records had the same time of change (the same second), so the order then could be wrong
        $varset->add("time", "number", nanotime());
        $varset->add("user", "text", AuthGetUid());
        $varset->add("type", "text", $type);
        $varset->add("resource_id", 'text', $change->getResourceId());
        $varset->doInsert('change');

        foreach ($change_proposals as $change) {
            $priority = 0;
            foreach ($change->getValues() as $value) {
                $varset->clear();
                $varset->add("change_id", "text", $change_id);
                $varset->add("selector", "text", $change->getSelector());
                $varset->add("priority", "number", $priority++);
                $varset->add("type", "text", gettype($value));
                $varset->add("value", "text", $value);
                $varset->doInsert('change_record');
            }
        }
        return true;
    }

    // array of unpacked ids
    public static function deleteChanges($resources_long_ids) {
        // it is quicker to split it into two deletes because of index type_resource_time
        $changes_ids = DB_AA::select('id', 'SELECT id FROM `change`', [['type', 'h'], ['resource_id', $resources_long_ids]]);
        $changes_ids = array_merge($changes_ids, DB_AA::select('id', 'SELECT id FROM `change`', [['type', 'p'], ['resource_id', $resources_long_ids]]));
        DB_AA::delete_low_priority('change_record', [['change_id', $changes_ids]]);
        DB_AA::delete_low_priority('change', [['type', 'h'], ['resource_id', $resources_long_ids]]);
        DB_AA::delete_low_priority('change', [['type', 'p'], ['resource_id', $resources_long_ids]]);
        return true;
    }

    /** deleteProposal
     * @param $change_id
     */
    function deleteProposal($change_id) {
        $varset = new Cvarset;
        $varset->doDeleteWhere('change_record', "change_id = '" . quote($change_id) . "'");
        $varset->clear();
        $varset->addkey("id", "text", $change_id);
        $varset->doDelete('change');
    }

    /** list of fields changed during last edit - dash ('-') separated
     * @param $resource_id
     * @return string
     */
    function lastChanged($resource_id): string {

        $chid = DB_AA::select1('id', "SELECT id FROM `change`", [['resource_id', $resource_id], ['type', 'h']], ['time-']);  // false if not found
        $ret = '';
        if ($chid) {
            $changes_arr = $this->getProposalByID($chid);
            if (is_array($changes_arr[$resource_id])) {
                $ret = implode('-', array_keys($changes_arr[$resource_id]));
            }
        }
        return $ret;
    }



    /** get array of change ids with time and user - [id=>[time,user]]
     * @param $resource_id
     * @return array
     */
    static function getChangeList($resource_id): array {
        $history_entries = !trim($resource_id) ? [] : DB_AA::select(['id'=> ['time', 'user']], 'SELECT `id`, `time`, `user` from `change`', [['resource_id',$resource_id], ['type','h']], ['time-']);
        array_walk($history_entries, function (&$v, $k) {$v['time'] = ChangesMonitor::toTimestamp((int)$v['time']);}); // if the change[time] is in nanoseconds convert it to seconds
        return $history_entries;
    }

    /** timestamp of last change
     * @param string $resource_id
     * @param string $selector
     * @return int
     */
    function lastChangeDate($resource_id, $selector): int {
        $time = 0;
        if (trim($resource_id) AND trim($selector)) {
            $time = DB_AA::select1('time', "SELECT time FROM `change`, `change_record`", [['change.id', 'change_record.change_id', 'j'], ['change.resource_id', $resource_id], ['change.type', 'h'], ['change_record.selector', $selector]], ['time-']);
        }
        return $time ? self::toTimestamp((int)$time) : 0;
    }

    /** value of the resource n steps back
     * @param string $resource_id
     * @param string $selector
     * @param int    $step
     * @return array ['value'=>.., 'time'=>.., 'user'=>.., 'type'=>..]
     */
    function changeByStep(string $resource_id, string $selector, int $step=-1): array {
        $value = [];
        if (trim($resource_id) AND trim($selector)) {
            $value = DB_AA::select1([], "SELECT `change_record`.`value`,`change`.`time`,`change`.`user`,`change`.`type` FROM `change`, `change_record`", [['change.id', 'change_record.change_id', 'j'], ['change.resource_id', $resource_id], ['change.type', 'h'], ['change_record.selector', $selector], ['change_record.value', '', 'FILLED']], ['time-'], abs($step));
        }
        return $value ?: [];
    }

    /** value of the resource at the time
     * @param string $resource_id
     * @param string $selector
     * @param int    $timestamp - time in seconds or nanoseconds
     * @return array ['value'=>.., 'time'=>.., 'user'=>.., 'type'=>..]
     */
    function changeByTime(string $resource_id, string $selector, int $timestamp): array {
        $value = [];
        // start debugging
        AA::$debug = 127;
        if (trim($resource_id) AND trim($selector) AND $timestamp) {
            $timestamp = self::toNanotime($timestamp);
            $value = DB_AA::select1([], "SELECT `change`.`id`,`change_record`.`value`,`change_record`.`selector`, `change`.`time`,`change`.`user`,`change`.`type` FROM `change`, `change_record`", [['change.id', 'change_record.change_id', 'j'], ['change.resource_id', $resource_id], ['change.type', 'h'], ['change.time', $timestamp, '>='], ['change_record.selector', $selector], ['change_record.value', '', 'FILLED']], ['time']);
            if (!$value) {
                // if not found change after the specified time, return the current value
                // works for items right now, but not for other resources
                $val = \AA_Items::getItemProperty($resource_id, $selector)?->getValues() ?? '';
                $value = ['value'=>$val, 'time'=>now(), 'user'=>'', 'type'=>'N'];  // type 'N' - probably not used yet, but it indicates, the value is not from history but from current state
            }
        }
        return $value ?: [];
    }

    /** timestamp of last change
     * @param string $resource_id
     * @param string $selector
     * @return array [['value'=>.., 'time'=>.., 'user'=>.., 'type'=>..]]
     */
    function changesAll(string $resource_id, string $selector): array {
        $values = [];
        if (trim($resource_id) AND trim($selector)) {
            $values = DB_AA::select([], "SELECT `change_record`.`value`,`change`.`time`,`change`.`user`,`change`.`type` FROM `change`, `change_record`", [['change.id', 'change_record.change_id', 'j'], ['change.resource_id', $resource_id], ['change.type', 'h'], ['change_record.selector', $selector], ['change_record.value', '', 'FILLED']], ['time-']);
        }
        return $values;
    }

    /** deleteProposalForSelector function
     * @param $resource_id
     * @param $selector
     */
    function deleteProposalForSelector($resource_id, $selector) {
        $changes_ids = GetTable2Array("SELECT DISTINCT change_id  FROM `change` LEFT JOIN `change_record` ON `change`.id = `change_record`.change_id
                                         WHERE `change`.resource_id = '" . quote($resource_id) . "' AND `change`.type = 'p' AND `change_record`.selector = '" . quote($selector) . "'", '', 'change_id');
        if (is_array($changes_ids)) {
            foreach ($changes_ids as $change_id) {
                $this->deleteProposal($change_id);
            }
        }
    }

    /** getProposals function
     * @return array proposals for given resource (like item_id)
     *  return value is array ordered by time of proposal
     * @param $resource_ids
     */
    function getProposals(array $resource_ids): array
    {
        return $this->_get($resource_ids, 'p');
    }

    /** getHistory function
     * @param $resource_ids
     * @return array
     */
    function getHistory(array $resource_ids): array
    {
        return $this->_get($resource_ids, 'h');
    }

    /** _get function
     * @param $resource_ids
     * @param $type
     * @return array all proposals for given resource (like item_id)
     *  return value is array ordered by time of proposal
     */
    function _get($resource_ids, $type): array
    {
        if (!is_array($resource_ids) OR (count($resource_ids) < 1)) {
            return [];
        }

        $ids4sql = sqlin("`change`.resource_id", $resource_ids);

        $sql = "SELECT `change`.resource_id, `change_record`.*
                                FROM `change` LEFT JOIN `change_record` ON `change`.id = `change_record`.change_id
                                WHERE $ids4sql
                                AND   `change`.type='$type'
                                ORDER BY `change`.resource_id, `change`.time, `change_record`.change_id, `change_record`.selector, `change_record`.priority";

        $changes = GetTable2Array($sql, '', 'aa_fields');

        $ret = [];
        if (is_array($changes)) {
            $garr = new AA_GeneralizedArray();
            foreach ($changes as $change) {
                if ($change['type']) {
                    $value = $change['value'];
                    settype($value, $change['type']);
                    $garr->add($value, [$change['resource_id'], $change['change_id'], $change['selector']]);
                }
            }
            $ret = $garr->getArray();
        }
        return $ret;
    }


    /** experimental display function - prints the table with all changes  */
    function display($resource_ids, $type = 'h'): void {
        if (!is_array($resource_ids) OR (count($resource_ids) < 1)) {
            return;
        }

        if ($arr = DB_AA::select([], "SELECT `change`.time, `change`.user, `change_record`.selector, `change_record`.value, `change_record`.priority, `change_record`.type as chtype, `change_record`.change_id, `change`.resource_id, `change`.type
                                FROM `change`, `change_record`", [['change.id', 'change_record.change_id' , 'j'], ["`change`.resource_id", $resource_ids], ['change.type',$type]], ['change.resource_id', 'change.time-', 'change_record.change_id', 'change_record.selector', 'change_record.priority'])) {
            echo "<div class='aa-table'><table class='aa-history'><tr><th>&nbsp;</th><th>field</th><th>value</th><th>priority</th><th>type</th><th>resource</th></tr>";
            $chid = '';
            foreach ($arr as $change) {
                if ($chid != $change['change_id']) {
                    echo "<tr><th colspan=6>" . date('Y-m-d H:i:s', self::toTimestamp((int)$change['time'])) . ' - ' . safe(perm_username($change['user'])) . " <small>($change[type], uid:$change[user], res:$change[resource_id], change:$change[change_id], time:$change[time])</small></th></tr>";
                    $chid = $change['change_id'];
                }
                echo "<tr><td>&nbsp;</td><td>$change[selector]</td><td><pre>".safe($change['value'])."</pre></td><td>$change[priority]</td><td>$change[chtype]</td><td>".safe($change['resource_id'])."</td></tr>";
            }
            echo "</table></div>";
        }

        //    print_r(array_keys(reset($arr)));
        //    array_unshift($arr, array_keys(reset($arr)));
        //    echo GetHtmlTable($arr, 'th');
    }


    /** getProposalByID function
     * @param $change_id
     * @return array
     */
    function getProposalByID($change_id): array {
        if (!$change_id) {
            return [];
        }
        $garr = new AA_GeneralizedArray();
        $changes = GetTable2Array("SELECT `change_record`.*, `change`.resource_id
                                FROM `change` LEFT JOIN `change_record` ON `change`.id = `change_record`.change_id
                                WHERE `change`.id = '" . quote($change_id) . "'
                                ORDER BY `change_record`.selector, `change_record`.priority", '', 'aa_fields');

        if (is_array($changes)) {
            foreach ($changes as $change) {
                if ($change['type']) {
                    $value = $change['value'];
                    settype($value, $change['type']);
                    $garr->add($value, [$change['resource_id'], $change['selector']]);
                }
            }
        }
        return $garr->getArray();
    }

    /**  if the change[time] is in nanoseconds convert it to seconds
     * @param int $sometime  time in seconds or nanoseconds
     * @return int
     */
    static function toTimestamp(int $sometime): int {
        return ($sometime >	10000000000) ? ($sometime / 1000000000) : $sometime;
    }

    /**  if the time is in seconds, convert it to nanoseconds for DB query
     * @param int $sometime  time in seconds or nanoseconds
     * @return int
     */
    static function toNanotime(int $sometime): int {
        return ($sometime >	10000000000) ? $sometime : ($sometime * 1000000000);
    }

    /**
     * Loads data from database for given history change (called in manager class)
     * and stores it in the 'Abstract Data Structure' for use with 'item' class
     *
     * @param zids $zids if ids to get from database
     *
     * @return array - Abstract Data Structure containing the history change data
     *                 {@link https://actionapps.org/faq/#1337}
     *@see GetItemContent(), itemview class, item class
     */
    public static function GetHistoryContent($zids): array
    {
        if (!$zids or $zids->count() < 1) {
            return [];
        }

        // construct WHERE clausule
        $sel_in = $zids->sqlin(false, true);

        // get history data
        $SQL = "SELECT * FROM `change` WHERE id $sel_in";

        $content = [];
        StoreTable2Content($content, $SQL, '', 'id');

        $additional = DB_AA::select([], 'SELECT * FROM `change_record`', [['change_id', $zids->longids()]]);
        foreach ($additional as $chrec) {
            $content[$chrec['change_id']][$chrec['selector']][$chrec['priority']] = ['value' => $chrec['value']];
        }
        return $content;
    }

    /** used in Manager class
     */
    public static function QueryHistoryZIDs(AA_Set $set): zids
    {
        $slice_id = $set->getModules()[0];
        $conds    = $set->getConds();
        $sort     = $set->getSort();

        $HISTORY_FIELDS = GetHistoryFields()->getArrayDeprecated();

        $where_sql    = MakeSQLConditions($HISTORY_FIELDS, $conds, 'LIKE', $foo);
        $order_by_sql = MakeSQLOrderBy($HISTORY_FIELDS, $sort, $foo);

        $SQL = "SELECT DISTINCT `change`.id FROM `change`, `item`  WHERE item.id = UNHEX(change.resource_id) AND item.slice_id = ".xpack_id($slice_id)." AND `change`.type = 'h'";

        //    $SQL  = "SELECT DISTINCT change.id FROM `change`, change_record WHERE change.id = change_record.change_id AND change.resource_id = '$slice_id' ";

        $SQL .= $where_sql.$order_by_sql;

        // get result --------------------------
        return GetZidsFromSQL($SQL, 'id', 'l');
}
}
