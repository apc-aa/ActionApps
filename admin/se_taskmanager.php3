<?php  //slice_id expected
/**
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
 * along with this program (LICENSE); if not, write tao the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

// @todo only_action option which prints on the output the result of the action
// Then it could be used as AJAX call for this action!

use AA\Later\Toexecute;

const AA_REGISTER_GLOBALS = true;
require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/varset.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/item.php3";
require_once __DIR__."/../include/actions.php3";
require_once __DIR__."/../central/include/actionapps.class.php";

if ( !IsSuperadmin() ) {
    MsgPage(StateUrl(self_base())."index.php3", _m("You do not have permission to manage ActioApps tasks"));
    exit;
}

/** Test Task */

//class AA_Task_Test implements \AA\Later\LaterInterface {
//
//    function __construct() {
//    }
//
//    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
//     *  @param array $params - numeric array of additional parameters for the execution passed in time of call
//     *  @return string - message about execution to be logged
//     *  @see \AA\Later\LaterInterface
//     */
//    public function toexecutelater($params=array()) {
//        // synchronize accepts array of sync_actions, so it is possible
//        // to do more action by one call
//        echo time(). " ";
//        sleep(25);
//        return "OK" ;
//    }
//}
//
//$toexecute = new AA\Later\Toexecute;
//$test_task = new AA_Task_Test();
//$toexecute->userQueue($test_task, array(), 'AA_Task_Test');


// Common manager settings for Tasks 
$manager_settings = GetToexecuteManagerSettings();

$manager = new AA_Manager('task'.AuthGetUid(),  $manager_settings);
$manager->performActions();

$set  = $manager->getSet();
$set->addCondition(new AA_Condition('execute_after', '==', Toexecute::TOEXECUTE_USER_TASK_TIME));
$set->addCondition(new AA_Condition('aa_user',       '==', AuthGetUid()));
$zids = AA::Metabase()->queryZids(['table'=>'toexecute'], $set);

$manager->displayPage($zids, 'sliceadmin', 'taskmanager');
page_close();


