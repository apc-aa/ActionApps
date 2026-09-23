<?php

namespace AA\Manageraction;

use AA\Central\Actionapps;
use AA\Central\RemoteHtml;
use AA\Manager;
use zids;

/** Manager action Central_OptimizeType - runs all Optimize classes of a type (like 'sql_update')
 *  on the selected AAs, as their service/sql_update.php would. Goes through the remote
 *  responder with the stored Central login (Do_Optimize_Type).
 */
class Central_OptimizeType extends AbstractManageraction
{
    /** Optimize type - 'sql_update' */
    public $optimize_type;

    /** 'test' | 'repair' */
    public $optimize_method;

    /** Constructor - fills the information about the optimize chain */
    public function __construct($id, $type, $method)
    {
        $this->optimize_type   = $type;
        $this->optimize_method = $method;
        parent::__construct($id);
    }

    /** Name of this Manager's action - test / update as the sql_update.php buttons.
     *  The literal label keeps its translations; sql_update is the only type Do_Optimize_Type runs. */
    public function getName(): string
    {
        return _m('Update DB (sql_update)') . ' - ' . (($this->optimize_method === 'repair') ? 'update' : 'test');
    }

    /** @inheritDoc */
    public function perform(Manager $manager, zids $item_zids, $action_param): string|false
    {
        if (count($item_zids) < 1) {
            return false;                                     // OK - no error
        }
        MoreTimeLimit(360);
        $ret = [];
        foreach ($item_zids as $aa_id) {
            $aa     = $this->actionapps($aa_id);
            $report = $aa->doOptimizeType($this->optimize_type, $this->optimize_method);
            // a passed chain starts 'OK '; anything else (failed chain, error) must not look alike
            $title  = str_starts_with($report, 'OK ') ? $aa->getName() : _m('Error: %1', [$aa->getName()]);
            // the report (or error text) is the remote AA's HTML - render it, but inert
            $ret[]  = RemoteHtml::sandboxed($title, $report);
        }
        return implode('', $ret);
    }

    /** the AA of the central_conf row */
    protected function actionapps($aa_id): Actionapps
    {
        return Actionapps::getActionapps($aa_id);
    }

    /** Checks if the user have enough permission to perform the action
     *
     * @param \AA\Manager  $manager
     *
     * @return bool
     */
    public function isPerm4Action($manager): bool
    {
        return IsSuperadmin();
    }
}
