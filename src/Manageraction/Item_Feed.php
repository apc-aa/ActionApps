<?php

namespace AA\Manageraction;

use AA\Manager;
use zids;

/** Manager action Item_Feed
 *  Export (Copy) items to another slice
 * @param $slice       - slice object - slice, from which we export
 * @param $item_arr    - array, where keys are unpacked ids of items prefixed by
 *                     'x' character (javascript purposes only)
 * @param $akce_param  - Special string, where destination slices are coded.
 *                     The format is "<status>-<unpacked_slice_id>,<status>-.."
 * @return false or error message
 */
class Item_Feed extends AbstractManageraction {

    /** specifies, to which bin the move should be performed */
    var $slice_id;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $slice_id) {
        $this->slice_id = $slice_id;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        return _m('Export to slice');
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        if (strlen($action_param) < 1) {
            return _m('No slice selected');
        }
        $export_to = explode(",", $action_param);          // <status>-<slice_id> pairs

        foreach ($item_zids as $it_id) {
            foreach ($export_to as $exp_slice_pair) {
                [$status, $sid] = explode("-", $exp_slice_pair);
                FeedItemTo($it_id, $this->slice_id, $sid, ($status == '1' ? 'y' : 'n'));
            }
        }
        return false;                                  // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return true;
    }
}