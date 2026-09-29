<?php

namespace AA\Manageraction;

use AA\IO\DB\DB_AA;
use AA\Managed_Table;
use AA\Manager;
use zids;

/** AA\Manageraction - Item manager actions. Just create new class and assign
 *  it to your manager
 */
class Polls_MoveItem extends AbstractManageraction
{
    /** specifies, to which bin the move should be performed */
    public $to_bin;

    /** Constructor - fills the information about the target bin */
    public function __construct($id, $to_bin)
    {
        $this->to_bin = $to_bin;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string
    {
        return match ($this->to_bin) {
            1 => _m('Move to Active'),
            2 => _m('Move to Holding bin'),
            3 => _m('Move to Trash'),
            default => "",
        };
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        $managed = $manager->getManaged();
        if (!($managed instanceof Managed_Table) || !is_long_id($module = $manager->getModuleId())) {
            return _m('This action does not work on this page.');
        }
        [$ids, $refused] = $managed->actionable($manager, $item_zids);
        if ($ids) {
            DB_AA::update('polls', [['status_code', $this->to_bin, 'i']], [['id', $ids], ['module_id', $module, 'l']]);
        }
        return $refused ? _m('Some of the selected polls were not moved - they belong to another module.') : false;
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        $current_bin = $manager->getBin();

        switch ($this->to_bin) {
            case 1:
                return IfSlPerm(PS_ITEMS2ACT) && $current_bin !== 'app' && $current_bin !== 'appb' && $current_bin !== 'appc';

            case 2:   // Folder2 is Holding bin - prepared for more than three bins
                return IfSlPerm(PS_ITEMS2HOLD) && $current_bin !== 'folder2';

            case 3:   // Folder3 is Trash
                return IfSlPerm(PS_ITEMS2TRASH) && $current_bin !== 'folder3';
        }
        // should not be reached
        return false;
    }
}
