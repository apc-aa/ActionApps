<?php
/**
 * File contains definitions of functions which corresponds with actions
 * on Item Manager page (admin/index.php3) - manipulates with central_confs
 *
 * Should be included to other scripts (admin/index.php3)
 *
 *   Move central_conf to app/hold/trash based on param
 * @param $status     string - static function parameter defined in manager action
 *                    in this case it holds bin number, where the central_confs should go
 * @param $item_arr   array, where keys are unpacked ids of items prefixed by
 *                    'x' character (javascript purposes only)
 * @param $akce_param mixed additional parameter for the action - not used here
 *
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Manageraction;

use AA\IO\DB\DB_AA;
use AA\Manager;
use AA\Manageraction\AbstractManageraction;
use AA\Util\Linkcheck;
use AA_Actionapps;
use zids;


/** Call remote AA_Optimize_* function
 *
 */
class Central_Optimize extends AbstractManageraction {

    /** class to be executed in remote AA */
    var $optimize_class;

    /** method to be called in optimize_class */
    var $optimize_method;

    /** Constructor - fills the information about the optimize method */
    function __construct($id, $class, $method) {
        $this->optimize_class  = $class;
        $this->optimize_method = $method;
        parent::__construct($id);
    }

    /** Name of this Manager's action */
    function getName() {
        $class = $this->optimize_class;
        return $class::name() . ' - ' . $this->optimize_method;
    }

    /** @inheritDoc */
    function perform(Manager $manager, zids $item_zids, $action_param) {
        $ret = [];

        foreach ($item_zids as $aa_id) {
            $aa    = AA_Actionapps::getActionapps($aa_id);
            $ret[] = $aa->doOptimize($this->optimize_class, $this->optimize_method);
        }
        return join('<br>', $ret);   // @todo we should rewrite outputs from AA\Manageraction\.. to some standard messages() system
    }

    /** Checks if the user have enough permission to perform the action
     * @param \AA\Manager $manager
     * @return bool
     */
    function isPerm4Action($manager) {
        return IsSuperadmin();
    }
}



