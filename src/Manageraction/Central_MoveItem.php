<?php

namespace AA\Manageraction;

use AA\IO\DB\DB_AA;
use AA\Manager;
use zids;

/** Manager action */
class Central_MoveItem extends AbstractManageraction {

    /** specifies, to which bin the move should be performed */
    var $to_bin;

    /** Constructor - fills the information about the target bin */
    function __construct($id, $to_bin) {
        $this->to_bin = $to_bin;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    public function getName(): string {
        return match ($this->to_bin) {
            1       => _m('Move to Active'),
            2       => _m('Move to Holding bin'),
            3       => _m('Move to Trash'),
            default => "",
        };
    }

    /** Per-row button definition — adds a small inline "Activate" / "Hold" / "Trash"
     *  button next to the row's Edit button. The 'cond' hides the button when the
     *  row's status_code already equals to_bin (no point clicking "Trash" on a
     *  trashed row).
     */
    protected function getButtonDef(): array {
        $label = match ($this->to_bin) {
            1       => _m('Activate'),
            2       => _m('Hold'),
            3       => _m('Trash'),
            default => '',
        };
        if (!$label) {
            return [];
        }
        return [
            'label' => $label,
            'attr'  => ['class' => 'aa-button aa-button-bin-' . $this->to_bin],
            // 'cond' is wrapped in {if1:cond:html} by AbstractManageraction::getButton.
            // {ifeq:{_#STATUS_C}:N:0:1} returns "0" when status_code == N, else "1",
            // so the button is hidden when the row is already in this bin.
            // The alias must be wrapped as {_#STATUS_C} — `{}` expressions evaluate
            // FIRST in AA, _#XXX___ aliases are substituted AFTER, so unwrapped
            // aliases inside template commands are literal text at compare time.
            'cond'  => '{ifeq:{_#STATUS_C}:'. $this->to_bin .':0:1}',
        ];
    }

    /** Render under the row's "..." overflow menu instead of inline. */
    public function isSecondaryButton(): bool {
        return true;
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        $item_ids = $item_zids->shortids();

        if ($item_ids) {
            $SQL = "UPDATE central_conf SET status_code = '" . $this->to_bin . "' WHERE id IN ('" . join_and_quote("','", $item_ids) . "')";
            DB_AA::sql($SQL);
        }
        return false;                                     // OK - no error
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    function isPerm4Action($manager):bool {
        $current_bin = $manager->getBin();

        /** for acces to Central you have to be superadmin */
        if (!IsSuperadmin()) {
            return false;
        }

        switch ($this->to_bin) {
            case 1:
                return $current_bin !== 'app' && $current_bin !== 'appb' && $current_bin !== 'appc';
            // Folder2 is Holding bin - prepared for more than three bins
            case 2:
                return ($current_bin !== 'hold');
            // Folder3 is Trash
            case 3:
                return ($current_bin !== 'trash');
        }

        // should not be reached
        return false;
    }
}