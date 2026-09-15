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
use AA_Fields;
use AA_GeneralizedArray;
use AA_Items;
use AA_Set;
use Cvarset;
use kcfinder\httpCache;
use zids;

class ChangesMonitor
{
    /** singleton
     *  This function makes sure, there is just ONE static instance if the class
     *  (this is better implementation, than static class variable - will work with subclasses)
     *   !!DO NOT use AA_Singleton - util may not be included
     */
    public static function singleton(): ChangesMonitor
    {
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
    public function addProposal(array $change_proposals): bool
    {
        return $this->_add($change_proposals, 'p');
    }

    /** addHistory function
     * @param ChangeProposal[] $change_proposals
     * @return bool
     */
    public function addHistory(array $change_proposals): bool
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
    public static function getDiff($id, $old, $new): array
    {
        $changes = [];
        if (is_array($old) && is_array($new)) {
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
    public static function getHistoryUrl($resource_id): string
    {
        return get_admin_url('aafinder.php', ['showhistory' => $resource_id]);
    }

    /** link to history table in AA Admin interface
     * @param      $resource_id
     * @return string url to history table in AA Admin interface
     */
    public static function getHistoryLink($resource_id): string
    {
        $history_entries = self::getChangeList($resource_id);
        return $history_entries ? a_href(self::getHistoryUrl($resource_id), _m('history (%1)', [count($history_entries)])) : '';
    }

    /** _add function
     * @param ChangeProposal[] $change_proposals
     * @param string $type 'h'|'p'  (= history or proposal)
     * @return bool
     */
    public function _add(array $change_proposals, $type): bool
    {
        if (!is_object($change = reset($change_proposals))) {
            return false;
        }

        $change_id = new_id();
        $varset = new Cvarset();
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
    public static function deleteChanges($resources_long_ids): bool
    {
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
    public function deleteProposal($change_id): void
    {
        $varset = new Cvarset();
        $varset->doDeleteWhere('change_record', "change_id = '" . quote($change_id) . "'");
        $varset->clear();
        $varset->addkey("id", "text", $change_id);
        $varset->doDelete('change');
    }

    /** list of fields changed during last edit - dash ('-') separated
     * @param $resource_id
     * @return string
     */
    public function lastChanged($resource_id): string
    {

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
    public static function getChangeList($resource_id): array
    {
        $history_entries = !trim($resource_id) ? [] : DB_AA::select(['id' => ['time', 'user']], 'SELECT `id`, `time`, `user` from `change`', [['resource_id',$resource_id], ['type','h']], ['time-']);
        array_walk($history_entries, function (&$v, $k) {
            $v['time'] = ChangesMonitor::toTimestamp((int) $v['time']);
        }); // if the change[time] is in nanoseconds convert it to seconds
        return $history_entries;
    }

    /** timestamp of last change
     * @param string $resource_id
     * @param string $selector
     * @return int
     */
    public function lastChangeDate($resource_id, $selector): int
    {
        $time = 0;
        if (trim($resource_id) && trim($selector)) {
            $time = DB_AA::select1('time', "SELECT time FROM `change`, `change_record`", [['change.id', 'change_record.change_id', 'j'], ['change.resource_id', $resource_id], ['change.type', 'h'], ['change_record.selector', $selector]], ['time-']);
        }
        return $time ? self::toTimestamp((int) $time) : 0;
    }

    /** value of the resource n steps back
     * @param string $resource_id
     * @param string $selector
     * @param int    $step
     * @return array ['value'=>.., 'time'=>.., 'user'=>.., 'type'=>..]
     */
    public function changeByStep(string $resource_id, string $selector, int $step = -1): array
    {
        $value = [];
        if (trim($resource_id) && trim($selector)) {
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
    public function changeByTime(string $resource_id, string $selector, int $timestamp): array
    {
        $value = [];
        // start debugging
        if (trim($resource_id) && trim($selector) && $timestamp) {
            $timestamp = self::toNanotime($timestamp);
            $value = DB_AA::select1([], "SELECT `change`.`id`,`change_record`.`value`,`change_record`.`selector`, `change`.`time`,`change`.`user`,`change`.`type` FROM `change`, `change_record`", [['change.id', 'change_record.change_id', 'j'], ['change.resource_id', $resource_id], ['change.type', 'h'], ['change.time', $timestamp, '>='], ['change_record.selector', $selector], ['change_record.value', '', 'FILLED']], ['time']);
            if (!$value) {
                // if not found change after the specified time, return the current value
                // works for items right now, but not for other resources
                $val = \AA_Items::getItemProperty($resource_id, $selector)?->getValues() ?? '';
                $value = ['value' => $val, 'time' => now(), 'user' => '', 'type' => 'N'];  // type 'N' - probably not used yet, but it indicates, the value is not from history but from current state
            }
        }
        return $value ?: [];
    }

    /** timestamp of last change
     * @param string $resource_id
     * @param string $selector
     * @return array [['value'=>.., 'time'=>.., 'user'=>.., 'type'=>..]]
     */
    public function changesAll(string $resource_id, string $selector): array
    {
        if (trim($resource_id) && trim($selector)) {
            return DB_AA::select([], "SELECT `change_record`.`value`,`change`.`time`,`change`.`user`,`change`.`type` FROM `change`, `change_record`", [['change.id', 'change_record.change_id', 'j'], ['change.resource_id', $resource_id], ['change.type', 'h'], ['change_record.selector', $selector], ['change_record.value', '', 'FILLED']], ['time-']);
        }
        return [];
    }

    /** deleteProposalForSelector function
     * @param $resource_id
     * @param $selector
     */
    public function deleteProposalForSelector($resource_id, $selector): void
    {
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
    public function getProposals(array $resource_ids): array
    {
        return $this->_get($resource_ids, 'p');
    }

    /** getHistory function
     * @param $resource_ids
     * @return array
     */
    public function getHistory(array $resource_ids): array
    {
        return $this->_get($resource_ids, 'h');
    }

    /** _get function
     * @param $resource_ids
     * @param $type
     * @return array all proposals for given resource (like item_id)
     *  return value is array ordered by time of proposal
     */
    public function _get($resource_ids, $type): array
    {
        if (!is_array($resource_ids) || count($resource_ids) < 1) {
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

    /** Render the change history for one or more resources as a flat timeline of expandable events. */
    public function display($resource_ids, $type = 'h'): void
    {
        if (!is_array($resource_ids) || count($resource_ids) < 1) {
            return;
        }

        $arr = $this->loadChanges($resource_ids, $type);
        if (!$arr) {
            echo "<p class='aa-empty-note'>" . _m('No recorded changes for this item.') . "</p>";
            return;
        }

        // Flat timeline: one expandable event per change (newest first). Markup + styling
        // (.aa-history-*) live in admin-ecn.css so this page and the AA finder's ?showhistory
        // improve together and the CSS is not re-emitted per call.
        $groups   = $this->groupByChange($arr);
        $cids     = array_keys($groups);     // newest-first
        $total    = count($cids);
        $revlabel = $this->buildRevLabels($cids, $groups);   // rN · date per change_id

        $forms = [];
        foreach ($cids as $i => $cid) {
            $g        = $groups[$cid];
            $rev      = $total - $i;                // newest first -> highest revision number on top
            $meta     = $g['meta'];
            $iid      = $meta['resource_id'];
            $item     = AA_Items::getItem(new zids($iid, 'l'));
            $slice_id = $item?->getSliceID();
            $flds     = $slice_id ? AA_Fields::factoryFor($slice_id) : null;
            $forms[]  = ($form = "frm$cid");
            $s_chid   = safe((string) $cid);        // also the anchor the Item-info history box links to
            $nfld     = count($g['rows']);
            $count    = $nfld === 1 ? _m('1 field changed') : _m('%1 fields changed', [$nfld]);

            echo "<details class='aa-page-section aa-history-event' id='aa-ch-$s_chid'>"
               . "<summary><span class='aa-tab-title aa-history-summary'>"
               . "<span class='aa-h-rev'>r$rev</span>"
               . "<span class='aa-h-time'>" . safe(date('Y-m-d H:i:s', self::toTimestamp((int) $meta['time']))) . "</span>"
               . "<span class='aa-h-who'>" . safe(perm_username($meta['user'])) . "</span>"
               . "<span class='aa-h-count'>$count</span>"
               . "<span class='aa-h-restore'>"
               . "<button type=submit form=$form class='btn btn-s btn-secondary' onclick='event.stopPropagation()'>" . _m('Restore') . "</button>"
               . "<input type=hidden name=slice_id form=$form value='" . safe((string) $slice_id) . "'>"
               . "</span>"
               . "</span></summary><div class='aa-history-body'>";

            // "Compare to" picker drives the RIGHT side of the diff (the target version); the LEFT is
            // fixed to this change's own before-value, so "compare to Current" is meaningful for every
            // row and never an empty diff. Default target = the value THIS change produced. Targets
            // offered = this change, each newer revision, and Current (live). The newest event's
            // product already IS the live value, so it has no alternative target -> no picker, it just
            // shows its before -> Current diff (and a single recorded change behaves the same way).
            [$label_a, $label_b] = $this->eventEndpointLabels($cids, $revlabel, $cid, 'after');

            if ($i !== 0) {
                $opts = "<option value='after' selected>" . $revlabel[$cid] . ' ' . _m('(this change)') . "</option>";
                for ($j = $i - 1; $j >= 1; $j--) {   // newer intermediate revisions, chronological
                    $opts .= "<option value='" . safe((string) $cids[$j]) . "'>" . $revlabel[$cids[$j]] . "</option>";
                }
                $opts .= "<option value='cur'>" . _m('Current (live)') . "</option>";
                echo "<div class='aa-history-compare'><label>" . _m('Compare to') . ' '
                   . "<select onchange=\"aaHistCompare(this,'$s_chid','" . safe((string) $iid) . "')\">"
                   . $opts
                   . "</select></label></div>";
            }

            // event-level raw ids (superadmin technical detail), one click down (dimmed, right-aligned)
            if (IsSuperadmin()) {
                echo "<div class='tabhlp aa-history-meta'>"
                   . _m('change %1 | uid %2 | %3 ns | resource %4', [$s_chid, safe((string) $meta['user']), safe((string) $meta['time']), safe((string) $iid)])
                   . "</div>";
            }

            // diff area (toggle + field table); the "Compare to" picker swaps this whole block in
            // place via AJAX, so the toggle's target label tracks the chosen version.
            echo "<div class='aa-history-diffarea' id='aa-diffwrap-$s_chid'>"
               . $this->renderEventDiff($arr, $g['rows'], $item, $flds, $iid, $form, 'after', $label_a, $label_b)
               . "</div></div></details>";
        }

        foreach ($forms as $form) {
            echo "\n<form id=$form action='" . get_aa_url('filler.php') . "' method='POST'></form>";
        }

        // (1) open + scroll to the change a history-box deep link (#aa-ch-<id>) targets - CSS cannot
        //     force a <details> open. (2) aaHistCompare: swap one event's diff cells for the chosen
        //     target version via the AA_Responder_Item_History_Diff responder (which re-checks the
        //     item edit permission), so this works from itemhistory.php and the finder's ?showhistory.
        $rurl = json_encode(get_aa_url('central/responder.php', [], false));   // plain url; JS appends ?command=... (correct <script> escaping)
        echo "<script>(()=>{const o=()=>{if(!location.hash)return;const e=document.getElementById(location.hash.slice(1));if(e&&e.classList.contains('aa-history-event')){e.open=true;e.scrollIntoView();}};addEventListener('hashchange',o);o();})();</script>"
           . "<script>function aaHistCompare(s,c,i){const w=document.getElementById('aa-diffwrap-'+c);if(!w)return;const u=$rurl,sep=u.indexOf('?')<0?'?':'&';w.style.opacity=.5;fetch(u+sep+'command=Item_History_Diff&id='+encodeURIComponent(i)+'&cid='+encodeURIComponent(c)+'&base='+encodeURIComponent(s.value)).then(r=>r.text()).then(h=>{w.innerHTML=h;const e=w.closest('.aa-history-event');if(e)e.dataset.view='diff';w.style.opacity='';}).catch(()=>{w.style.opacity='';});}"
           . "function aaHistView(b,v){const e=b.closest('.aa-history-event');if(!e)return;e.dataset.view=v;e.querySelectorAll('.aa-h-vbtn').forEach(x=>x.classList.toggle('is-active',x===b));}"
           . "function aaHistCopy(b){const p=b.nextElementSibling;if(!p||!navigator.clipboard)return;navigator.clipboard.writeText(p.textContent).then(()=>{b.classList.add('is-copied');setTimeout(()=>b.classList.remove('is-copied'),1200);});}</script>";
    }

    /** Load all recorded change_record rows for the given resource(s), newest-first (the timeline). */
    private function loadChanges($resource_ids, string $type): array
    {
        return DB_AA::select([], "SELECT `change`.time, `change`.user, `change_record`.selector, `change_record`.value, `change_record`.priority, `change_record`.type as chtype, `change_record`.change_id, `change`.resource_id, `change`.type
                                FROM `change`, `change_record`", [['change.id', 'change_record.change_id' , 'j'], ["`change`.resource_id", $resource_ids], ['change.type',$type]], ['change.resource_id', 'change.time-', 'change_record.change_id', 'change_record.selector', 'change_record.priority']) ?: [];
    }

    /** Group the flat change_record rows by change_id, preserving order (newest-first). */
    private function groupByChange(array $arr): array
    {
        $groups = [];
        foreach ($arr as $key => $row) {
            $groups[$row['change_id']]['meta'] ??= $row;
            $groups[$row['change_id']]['rows'][] = ['key' => $key, 'row' => $row];
        }
        return $groups;
    }

    /**
     * One event's diff area: the Diff / version-A / version-B toggle (the two buttons labelled by the
     * actual revisions being compared) followed by the per-field table. The "Compare to" picker swaps
     * this whole block in place (AJAX), so the toggle's target label tracks the chosen version. Shared
     * by the initial page render and renderEventDiffFragment.
     */
    private function renderEventDiff(array $arr, array $rows, $item, $flds, string $iid, string $form, string $base, string $label_a, string $label_b): string
    {
        return "<span class='aa-h-viewtoggle' role='group' aria-label='" . _m('View') . "'>"
             . "<button type='button' class='aa-h-vbtn is-active' onclick=\"aaHistView(this,'diff')\">" . _m('Diff') . "</button>"
             . "<button type='button' class='aa-h-vbtn' onclick=\"aaHistView(this,'a')\">" . $label_a . "</button>"
             . "<button type='button' class='aa-h-vbtn' onclick=\"aaHistView(this,'b')\">" . $label_b . "</button>"
             . "</span>"
             . "<table class='aa-history-fields'><thead><tr>"
             . "<th scope=col>" . _m('Field') . "</th><th scope=col>" . _m('Change') . "</th>"
             . "<th scope=col>" . _m('Restore') . "</th>"
             . "</tr></thead><tbody>"
             . $this->renderEventFields($arr, $rows, $item, $flds, $iid, $form, $base)
             . "</tbody></table>";
    }

    /**
     * Render one change event's per-field rows. The LEFT side of each diff is fixed to the value
     * just before this change (the previous revision / Original); the RIGHT side is the chosen
     * target version $base ('after' = the value this change produced, 'cur' = the current live value,
     * or another change_id). Left precedes right in time, so the diff reads green=added / red=removed.
     */
    private function renderEventFields(array $arr, array $rows, $item, $flds, string $iid, string $form, string $base): string
    {
        $html = '';
        $tech = IsSuperadmin();   // raw selector / seq / php-type are a superadmin technical detail
        foreach ($rows as $r) {
            $key    = $r['key'];
            $change = $r['row'];
            $fid    = $change['selector'];
            $pri    = $change['priority'];
            $fname  = ($flds && ($n = (string) $flds->getProperty($fid, 'name')) !== '') ? $n : (string) $fid;
            // field chip; editors see the friendly name, superadmins also the raw selector / seq / php-type
            $chip   = "<span class='aa-h-field-chip'>" . safe($fname) . "</span>";
            if ($tech) {
                $chip .= "<span class='tabhlp aa-h-field-sel'>" . safe((string) $fid) . ' &middot; ' . _m('seq %1', [safe((string) $pri)]) . ' &middot; ' . safe((string) $change['chtype']) . "</span>";
            }

            $before = (string) $change['value'];   // value before this change = left (A), fixed
            $target = (string) ($this->targetValue($arr, $key, $fid, $pri, $base, $item) ?? '');   // right (B)

            $diff = Difftext::renderInline($before, $target);
            $diff = $diff !== '' ? $diff : "<span class='aa-h-nochange'>" . _m('No change between these versions.') . "</span>";

            // three stacked views toggled by the per-event toggle buttons (CSS keyed on the event's
            // data-view); A/B show the raw value with a copy-to-clipboard button.
            $diff_cell = "<div class='aa-h-views'>"
                       . "<div class='aa-h-view aa-h-view-diff'>$diff</div>"
                       . "<div class='aa-h-view aa-h-view-a'>" . $this->codeView($before) . "</div>"
                       . "<div class='aa-h-view aa-h-view-b'>" . $this->codeView($target) . "</div>"
                       . "</div>";

            // restore checkbox enables the (adjacent) hidden input that feeds the per-change form
            $restore = "<input form=$form type=hidden name='aa[u" . safe((string) $iid) . "][" . safe((string) $fid) . "]' value=\"" . safe((string) $change['value']) . "\" disabled>"
                     . "<input type=checkbox onchange=\"this.previousElementSibling.disabled = !this.checked\"> " . safe($fname);

            $html .= "<tr><td>$chip</td><td>$diff_cell</td><td><label class='aa-h-restore-field'>$restore</label></td></tr>";
        }
        return $html;
    }

    /** A single version's raw value with a copy-to-clipboard button (the Version A / Version B views). */
    private function codeView(string $val): string
    {
        if ($val === '') {
            return "<span class='aa-h-nochange'>" . _m('(empty)') . "</span>";
        }
        return "<div class='aa-h-code'>"
             . "<button type='button' class='aa-h-copy' onclick='aaHistCopy(this)' title='" . _m('Copy to clipboard') . "'>" . _m('Copy') . "</button>"
             . "<pre>" . safe($val) . "</pre>"
             . "</div>";
    }

    /**
     * AJAX entry point (AA_Responder_Item_History_Diff): re-render one event's diff area against the
     * chosen target version. Validates $cid/$base against the loaded history and returns '' for
     * unknowns, so the request parameters cannot reach a query or the markup unchecked.
     */
    public function renderEventDiffFragment(string $iid, string $cid, string $base): string
    {
        $arr = $this->loadChanges([$iid], 'h');
        if (!$arr) {
            return '';
        }
        $groups = $this->groupByChange($arr);
        if (!isset($groups[$cid])) {
            return '';
        }
        $cids = array_keys($groups);
        if ($base !== 'after' && $base !== 'cur') {   // a specific revision target: accept only one strictly newer than this change
            $i_base = array_search($base, $cids, true);
            $i_cid  = array_search($cid, $cids, true);
            if ($i_base === false || $i_base >= $i_cid) {
                $base = 'after';
            }
        }
        $revlabel = $this->buildRevLabels($cids, $groups);
        [$label_a, $label_b] = $this->eventEndpointLabels($cids, $revlabel, $cid, $base);
        $item = AA_Items::getItem(new zids($iid, 'l'));
        $flds = ($sid = $item?->getSliceID()) ? AA_Fields::factoryFor($sid) : null;
        return $this->renderEventDiff($arr, $groups[$cid]['rows'], $item, $flds, $iid, "frm$cid", $base, $label_a, $label_b);
    }

    /** rN &middot; date label per change_id (newest-first $cids): highest rN on top, dated when that state went live. */
    private function buildRevLabels(array $cids, array $groups): array
    {
        $total    = count($cids);
        $revlabel = [];
        foreach ($cids as $i => $cid) {
            $revlabel[$cid] = 'r' . ($total - $i) . ' &middot; '
                . safe(date('Y-m-d H:i', self::toTimestamp((int) $groups[$cid]['meta']['time'])));
        }
        return $revlabel;
    }

    /**
     * The two diff-endpoint labels for event $cid against target $base: [left, right]. Left = the
     * state before this change (the previous revision, or "Original" when this is the oldest event).
     * Right = the chosen target: the live value ('cur'), this change's product ('after' = the live
     * value when this is the newest event), or a specific revision's product.
     */
    private function eventEndpointLabels(array $cids, array $revlabel, string $cid, string $base): array
    {
        $i       = array_search($cid, $cids, true);
        $total   = count($cids);
        $label_a = ($i !== false && $i + 1 < $total) ? $revlabel[$cids[$i + 1]] : _m('Original');
        if ($base === 'cur' || ($base === 'after' && $i === 0)) {
            $label_b = _m('Current (live)');
        } elseif ($base === 'after') {
            $label_b = $revlabel[$cid];
        } else {
            $label_b = $revlabel[$base] ?? _m('Current (live)');
        }
        return [$label_a, $label_b];
    }

    /**
     * The RIGHT side of the diff - the value of the chosen target version: the current live value
     * ('cur'), the value this change produced ('after'), or the value produced by a specific revision
     * (its change_id). Null when the field has no value in that version.
     */
    private function targetValue(array $arr, int $key, string $fid, string $pri, string $base, $item): ?string
    {
        if ($base === 'cur') {
            return $this->liveValue($item, $fid, $pri);
        }
        if ($base === 'after') {
            return $this->valueAfter($arr, $key, $fid, $pri, $item);
        }
        return $this->versionAfterCid($arr, $base, $fid, $pri, $item);
    }

    /** The field's current live value (the newest state), or null when absent (e.g. a purged item). */
    private function liveValue($item, string $fid, string $pri): ?string
    {
        $v = $item?->getAaValue($fid)?->getValue($pri);
        return $v === null ? null : (string) $v;
    }

    /**
     * The field's value AFTER the change at row index $idx - i.e. the version that change produced.
     * Each change_record stores the value BEFORE the change, so the "after" value is the recorded
     * value of the nearest NEWER change of the same field, or the current live value when this is
     * the field's newest change. Null when even the live value is absent (e.g. purged item).
     */
    private function valueAfter(array $arr, int $idx, string $fid, string $pri, $item): ?string
    {
        for ($i = $idx - 1; $i >= 0; $i--) {   // smaller index = newer change in the newest-first list
            if ($arr[$i]['selector'] == $fid && $arr[$i]['priority'] == $pri) {
                return (string) $arr[$i]['value'];
            }
        }
        return $this->liveValue($item, $fid, $pri);
    }

    /** The field's value produced by revision $cid = valueAfter() taken from that change's position. */
    private function versionAfterCid(array $arr, string $cid, string $fid, string $pri, $item): ?string
    {
        foreach ($arr as $i => $row) {
            if ((string) $row['change_id'] === $cid) {
                return $this->valueAfter($arr, $i, $fid, $pri, $item);
            }
        }
        return null;
    }

    /** getProposalByID function
     * @param $change_id
     * @return array
     */
    public function getProposalByID($change_id): array
    {
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
    public static function toTimestamp(int $sometime): int
    {
        return ($sometime >    10000000000) ? (int) ($sometime / 1000000000) : $sometime;
    }

    /**  if the time is in seconds, convert it to nanoseconds for DB query
     * @param int $sometime  time in seconds or nanoseconds
     * @return int
     */
    public static function toNanotime(int $sometime): int
    {
        return ($sometime >    10000000000) ? $sometime : ($sometime * 1000000000);
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
        if (!$zids || $zids->isEmpty()) {
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

        // No DISTINCT: item.id is the primary key and is bound by equality, so the
        // join yields at most one item row per change row - each change.id can
        // appear only once. MySQL cannot prove that (the key is bound to an
        // expression, not a constant), so a DISTINCT here would really build a
        // temp table over the whole result: measured ~25% slower on 334k rows.
        // Joining change_record (see below) WOULD multiply the rows - such a
        // query needs DISTINCT back.
        $SQL = "SELECT `change`.id FROM `change`, `item`  WHERE item.id = UNHEX(change.resource_id) AND item.slice_id = " . xpack_id($slice_id) . " AND `change`.type = 'h'";

        //    $SQL  = "SELECT DISTINCT change.id FROM `change`, change_record WHERE change.id = change_record.change_id AND change.resource_id = '$slice_id' ";

        $SQL .= $where_sql . $order_by_sql;

        // get result --------------------------
        return GetZidsFromSQL($SQL, 'l');
    }
}
