<?php
/**
 * File contains definitions of functions which corresponds with actions
 * on Item Manager page (admin/index.php3) - manipulates with items
 *
 * Should be included to other scripts (admin/index.php3)
 *
 *   Move item to app/hold/trash based on param
 *  @param $status    - static function parameter defined in manager action
 *                   in this case it holds bin number, where the items should go
 *  @param $item_arr  - array, where keys are unpacked ids of items prefixed by
 *                   'x' character (javascript purposes only)
 *  @param $akce_param - additional parameter for the action - not used here
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


use AA\Manageraction\Manageractions;


//------------------ Actions for Task Manager --------------------------------

/** Generates manager settings for aa_toexecute.php and se_taskmanager.php
 * @return \AA\Managed - manager settings
 */
function GetToexecuteManagerSettings(): \AA\Managed {
    $actions   = new Manageractions;
    $actions->addAction(new \AA\Manageraction\Taskmanager_Execute('ExecuteTaskAction'));
    $actions->addAction(new \AA\Manageraction\Taskmanager_Delete('DeleteTaskAction'));

    //$switches  = new AA\Manageractions;  // we do not need switches here

    $managed = AA::Metabase()->getManagerConf('toexecute', $actions);
    $managed->settings['itemview']['format']['compact_top'] = '
                                        <div class="aa-table">
                                          <table>
                                            <tbody><tr>
                                              <th width="30">&nbsp;</th>
                                              <th>id</th>
                                              <th>created</th>
                                              <th>execute after</th>
                                              <th>aa_user</th>
                                              <th>priority</th>
                                              <th>selector</th>
                                              <th>object</th>
                                              <th>params</th>
                                            </tr>';
    $managed->settings['itemview']['format']['odd_row_format'] = '
                                      <tr><td width="30"><input name="chb[x_#ID______]" value="" type="checkbox"></td>
                                        <td>_#ID______</td>
                                        <td>_#CREATED_</td>
                                        <td>_#EXECUTE_</td>
                                        <td>_#AA_USER_</td>
                                        <td>_#PRIORITY</td>
                                        <td>_#SELECTOR</td>
                                        <td>{safe:{shorten:{_#OBJECT__}:250:3}}</td>
                                        <td>{safe:{shorten:{_#PARAMS__}:250:3}}</td>
                                    </tr>';                      // <td>_#OBJECT__</td><td>_#EXECUTE_</td>

    $managed->settings['searchbar']['default_sort'] =  [0 => ['execute_after' => 'a']];
    return $managed;
}
