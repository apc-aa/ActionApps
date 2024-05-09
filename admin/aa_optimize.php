<?php
 /**
 *
 * PHP version 7.2+
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
 * @package   Maintain
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
*/

use AA\Manageraction\AbstractManageraction;
use AA\Manageraction\Manageractions;
use AA\Optimize\AbstractOptimize;
use AA\Manager;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";


/** Manager action Item_DeleteTrash - Handler for DeleteTrash switch
 *  Delete all items in the trash bin
 */
class Optimize_Action_Test extends AbstractManageraction {

    const ACTION_NAME = 'test';

    protected function doAction(AbstractOptimize $optimizer): bool { return $optimizer->test(); }

    /** @inheritDoc */
    public function getName(): string { return _m('Test'); }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        $Msg = '';
        foreach ($item_zids as $optimize_class) {
            /** @var AbstractOptimize $optimizer */
            $optimizer = AA_Serializable::factory('AA\\Optimize\\'.$optimize_class);
            if ($optimizer) {
                if ($optimizer->hasAction(static::ACTION_NAME)) {
                    AbstractOptimize::$action_results[$optimize_class] = $this->doAction($optimizer);
                    AbstractOptimize::$action_results_text[$optimize_class] = $optimizer->report();
                } else {
                    AbstractOptimize::$action_results_text[$optimize_class] = _m('No %1 action in %2.', [static::ACTION_NAME, $optimize_class]);
                }
            } else {
                AbstractOptimize::$action_results_text[$optimize_class] = _m('No optimize class found (%1).', [$optimize_class]);
            }
            $Msg .= AbstractOptimize::$action_results_text[$optimize_class];
        }

        return $Msg;
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool|string
     */
    function isPerm4Action($manager) {
        /** @todo Should be changed to different permission */
        // if we want to use it as "action" (not "switch"), then we should be in trash bin
        return  IsSuperadmin();
    }


    /** @inheritDoc */
    protected function getButtonDef(): array {
        return [
            'label' => $this->getName(),
            'attr'  => ['class'=>'aa-button'],
            'cond'  => '{ifin:|{@actions:|}|:|'.static::ACTION_NAME.'|:1}'
        ];
    }
}

class Optimize_Action_Test4modules extends Optimize_Action_Test {
    const ACTION_NAME = 'test4modules';

    protected function doAction(AbstractOptimize $optimizer): bool { return $optimizer->test4modules([AA::$module_id]); }

    /** @inheritDoc */
    public function getName(): string { return _m('Test current'); }
}

class Optimize_Action_Repair extends Optimize_Action_Test {
    const ACTION_NAME = 'repair';

    protected function doAction(AbstractOptimize $optimizer): bool { return $optimizer->repair(); }

    /** @inheritDoc */
    public function getName(): string { return _m('Repair'); }
}

class Optimize_Action_Repair4modules extends Optimize_Action_Test {
    const ACTION_NAME = 'repair4modules';

    protected function doAction(AbstractOptimize $optimizer): bool { return $optimizer->repair4modules([AA::$module_id]); }

    /** @inheritDoc */
    public function getName(): string { return _m('Repair current'); }
}


if (!IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You don't have permissions to do optimize tests."), "admin");
    exit;
}

MoreTimeLimit(); // set_time_limit to 5 minutes (at least)

$apage = new AA_Adminpageutil('aaadmin','optimize');
$apage->setTitle(_m("Admin - Optimize a Repair ActionApps"));

$actions   = new Manageractions;
$actions->addAction(new Optimize_Action_Test('OptimizeTest'));
$actions->addAction(new Optimize_Action_Test4modules('OptimizeTest4modules'));
$actions->addAction(new Optimize_Action_Repair('OptimizeRepair'));
$actions->addAction(new Optimize_Action_Repair4modules('OptimizeRepair4modules'));

$managed = AA_Components::getManagerConf('AA\\Optimize\\', 'AA\\Optimize\\AbstractOptimize', $actions);
$managed->setColumn('name', '<b class="{ifeq:{result}:OK:okmsg:ERR:err}">{name}</b><div>{description}</div>{ifset:{resulttxt}:<div><em>_#1</em></div>}' );
$managed->setColumns2show(['name','types']);

$apage->printWithManager(new Manager('optimize' . AA::$module_id, $managed));

//    AA::Pagecache()->invalidateFor($invalidatefor);
