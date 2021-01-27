<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 6.6.19
 * Time: 10:50
 */

namespace AA\Util;

use AA\IO\DB\DB_AA;

class ChangesMonitor {

    /** singleton
     *  This function makes sure, there is just ONE static instance if the class
     *  (this is better implementation, than static class variable - will work with subclasses)
     *   !!DO NOT use AA_Singleton - util may not be included
     */
    public static function singleton() {
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
    function addProposal($change_proposals) {
        return $this->_add($change_proposals, 'p');
    }

    /** addHistory function
     * @param $change_proposals
     * @return bool
     */
    function addHistory($change_proposals) {
        return $this->_add($change_proposals, 'h');
    }

    /** Prepares AA\Util\ChangeProposal array from old values and new values
     *  Usage: AA\Util\ChangesMonitor::singleton()->addHistory(AA\Util\ChangesMonitor::getDiff($id, $old, $new));
     */
    static function getDiff($id, $old, $new) {
        $changes = [];
        if (is_array($old) AND is_array($new)) {
            foreach ($new as $fid => $a) {
                if ($old[$fid] != $a) {
                    $changes[] = new \AA\Util\ChangeProposal($id, $fid, [$old[$fid]]);
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
        return get_admin_url('aafinder.php3', ['showhistory' => $resource_id]);
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
     * @param $change_proposals
     * @param $type
     * @return bool
     */
    function _add($change_proposals, $type) {
        global $auth;

        if (!is_array($change_proposals)) {
            $change_proposals = [$change_proposals];
        }
        if (!is_object($change = reset($change_proposals))) {
            return true;
        }

        $change_id = new_id();
        $varset = new \Cvarset;
        $varset->addkey("id", "text", $change_id);
        $varset->add("time", "number", now());
        $varset->add("user", "text", is_object($auth) ? $auth->auth["uid"] : '');
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
    static public function deleteChanges($resources_long_ids) {
        // it is qucker to split it into two deletes because of index type_resource_time
        $changes_ids = DB_AA::select('id', 'SELECT id FROM `change`', [
            ['type', 'h'],
            ['resource_id', $resources_long_ids]
        ]);
        $changes_ids = array_merge($changes_ids, DB_AA::select('id', 'SELECT id FROM `change`', [
            [
                'type',
                'p'
            ],
            ['resource_id', $resources_long_ids]
        ]));
        DB_AA::delete_low_priority('change_record', [['change_id', $changes_ids]]);

        DB_AA::delete_low_priority('change', [['type', 'h'], ['resource_id', $resources_long_ids]]);
        DB_AA::delete_low_priority('change', [['type', 'p'], ['resource_id', $resources_long_ids]]);
        return true;
    }

    /** deleteProposal
     * @param $change_id
     */
    function deleteProposal($change_id) {
        $varset = new \Cvarset;
        $varset->doDeleteWhere('change_record', "change_id = '" . quote($change_id) . "'");
        $varset->clear();
        $varset->addkey("id", "text", $change_id);
        $varset->doDelete('change');
    }

    /** list of fields changed during last edit - dash ('-') separated */
    function lastChanged($resource_id) {

        $chid = DB_AA::select1("SELECT id FROM `change`", 'id', [
            ['resource_id', $resource_id],
            ['type', 'h']
        ], ['time-']);  // false if not found
        $ret = '';
        if ($chid) {
            $changes_arr = $this->getProposalByID($chid);
            if (is_array($changes_arr[$resource_id])) {
                $ret = join('-', array_keys($changes_arr[$resource_id]));
            }
        }
        return $ret;
    }

    /** get array of change ids with time and user - [id=>[time,user]]
     * @param $resource_id
     * @return array
     */
    static function getChangeList($resource_id) {
        return DB_AA::select(['id'=> ['time', 'user']], 'SELECT `id`, `time`, `user` from `change`', [['resource_id',$resource_id], ['type','h']], ['time-']);
    }

    /** timestamp of last change */
    function lastChangeDate($resource_id, $selector) {
        $time = DB_AA::select1("SELECT time FROM `change`, `change_record`", 'time', [['change.id', 'change_record.change_id' , 'j'], ['change.resource_id', $resource_id], ['change.type', 'h'], ['change_record.selector',$selector]], ['time-']);
        return $time ? $time : 0;
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
     * @return \all proposals for given resource (like item_id)
     *  return value is array ordered by time of proposal
     * @param $resource_ids
     */
    function getProposals($resource_ids) {
        return $this->_get($resource_ids, 'p');
    }

    /** getHistory function
     * @param $resource_ids
     * @return \all
     */
    function getHistory($resource_ids) {
        return $this->_get($resource_ids, 'h');
    }

    /** _get function
     * @return \all proposals for given resource (like item_id)
     *  return value is array ordered by time of proposal
     * @param $resource_ids
     * @param $type
     */
    function _get($resource_ids, $type) {
        $garr = new \AA_GeneralizedArray();
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

        if (is_array($changes)) {
            foreach ($changes as $change) {
                if ($change['type']) {
                    $value = $change['value'];
                    settype($value, $change['type']);
                    $garr->add($value, [$change['resource_id'], $change['change_id'], $change['selector']]);
                }
            }
        }
        return $garr;
    }


    /** experimental display function - prints the table with all changes  */
    function display($resource_ids, $type = 'h') {
        if (!is_array($resource_ids) OR (count($resource_ids) < 1)) {
            return [];
        }

        if ($arr = DB_AA::select([], "SELECT `change`.time, `change`.user, `change_record`.selector, `change_record`.value, `change_record`.priority, `change_record`.type as chtype, `change_record`.change_id, `change`.resource_id, `change`.type
                                FROM `change`, `change_record`", [['change.id', 'change_record.change_id' , 'j'], ["`change`.resource_id", $resource_ids], ['change.type',$type]], ['change.resource_id', 'change.time', 'change_record.change_id', 'change_record.selector', 'change_record.priority'])) {
            echo "<div class='aa-table'><table class='aa-history'><tr><th>&nbsp;</th><th>field</th><th>value</th><th>priority</th><th>type</th><th>resource</th></tr>";
            $chid = '';
            foreach ($arr as $change) {
                if ($chid != $change['change_id']) {
                    echo "<tr><th colspan=6>" . date('Y-m-d H:i:s', $change['time']) . ' -' . perm_username($change['user']) . " <small>($change[type], uid:$change[user], res:$change[resource_id], change:$change[change_id])</small></th></tr>";
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
     * @return \AA_GeneralizedArray|array
     */
    function getProposalByID($change_id) {
        $garr = new \AA_GeneralizedArray();
        if (!$change_id) {
            return $garr;
        }
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
}