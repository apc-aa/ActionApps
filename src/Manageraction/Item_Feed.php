<?php

namespace AA\Manageraction;

use AA\IO\DB\DB_AA;
use AA\Managed;
use AA\Manager;
use AA\Optimize\CheckObjectFormRefusals;
use AA_Slice;
use zids;

/** Manager action Item_Feed
 *  Export (Copy) items to another slice. The action parameter codes the destination slices as
 *  "<status>-<unpacked_slice_id>,<status>-..", status 1 - active, 2 - holding bin (admin/feed_to.php)
 */
class Item_Feed extends AbstractManageraction
{
    /** specifies, to which bin the move should be performed */
    public $slice_id;

    /** Constructor - fills the information about the target bin */
    public function __construct($id, $slice_id)
    {
        $this->slice_id = $slice_id;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string
    {
        return _m('Export to slice');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        if (!is_string($action_param) || ($action_param === '')) {
            return _m('No slice selected');
        }
        $msg     = [];
        $targets = [];
        foreach (explode(",", $action_param) as $exp_slice_pair) {          // <status>-<slice_id> pairs
            [$status, $sid] = array_pad(explode("-", $exp_slice_pair, 2), 2, '');
            if (!in_array($status, ['1', '2'], true)) {                     // 0 - do not export
                continue;
            }
            $sid      = strtolower($sid);
            $approved = ($status === '1') ? 'y' : 'n';
            if (!AA_Slice::isStoredSlice($sid)) {
                $msg[] = _m('Items can be exported only to a slice.');
            } elseif (!self::mayFeedTo($sid, $approved)) {
                $msg[] = _m('You do not have permission to export items to the slice %1.', [AA_Slice::getModuleNameSafe($sid) ?: $sid]);
            } else {
                $targets[] = [$sid, $approved];
            }
        }
        if (!$targets && !$msg) {
            return _m('No slice selected');
        }

        [$items, $refused] = $targets ? $this->ownItems($item_zids) : [[], false];
        foreach ($items as $it_id) {
            foreach ($targets as [$sid, $approved]) {
                $this->feed($it_id, $sid, $approved);
            }
        }
        if ($refused) {
            $msg[] = _m('Some of the selected items were not exported - they are not items of this slice.');
        }
        return $msg ? implode(' ', array_unique($msg)) : false;
    }

    /** the same rights feed_to.php offers the slice for: an author there, an editor for the approved items */
    private static function mayFeedTo(string $sid, string $approved): bool
    {
        return IfSlPerm(PS_EDIT_SELF_ITEMS, $sid) && (($approved !== 'y') || IfSlPerm(PS_ITEMS2ACT, $sid));
    }

    /** The items of $zids in the slice of the page. The ids come from the POST - an item deleted
     *  meanwhile is skipped silently, an item of another slice refused and logged.
     *  @return array{0: list<string>, 1: bool}  the item ids, whether any was refused
     */
    private function ownItems(zids $zids): array
    {
        if ($zids->isEmpty()) {
            return [[], false];
        }
        $slice_id = strtolower((string) $this->slice_id);
        $stored   = DB_AA::select(['unpackid' => 'sid'], 'SELECT LOWER(HEX(`id`)) AS unpackid, LOWER(HEX(`slice_id`)) AS sid FROM `item`', [$zids->getDbWhereArray()]);
        $items    = [];
        $refused  = false;
        foreach (array_unique(array_map('strtolower', $zids->longids())) as $id) {
            if (!isset($stored[$id])) {
                continue;
            }
            if ($stored[$id] === $slice_id) {
                $items[] = $id;
            } else {
                Managed::logRefusal($slice_id, $id, CheckObjectFormRefusals::ITEM_EXPORT, CheckObjectFormRefusals::ITEM_EXPORT, $stored[$id]);
                $refused = true;
            }
        }
        return [$items, $refused];
    }

    /** copies the item to the slice */
    protected function feed(string $item_id, string $destination_id, string $approved): void
    {
        FeedItemTo($item_id, $this->slice_id, $destination_id, $approved);
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        return IfSlPerm(PS_EDIT_SELF_ITEMS, $this->slice_id) || IfSlPerm(PS_EDIT_ALL_ITEMS, $this->slice_id);
    }
}
