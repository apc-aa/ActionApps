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

use AA\Manager;

//const AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";

if ( !IsSuperadmin() ) {
    MsgPage(_m("You do not have permission to manage ActionApps logs"));
    exit;
}

$managed = AA::Metabase()->getManagerConf('searchlog');

// 	id, date, query, found_count, search_time, user, additional1

$managed->settings['itemview']['format']['compact_top']    = '
                                          <table border="0" cellpadding="5" cellspacing="0">
                                            <tbody><tr>
                                              <th width="30">&nbsp;</th>
                                              <th>date</th>
                                              <th>query</th>
                                              <th>found_count</th>
                                              <th>search_time</th>
                                              <th>additional1</th>
                                              <th>id</th>
                                              <th>user</th>
                                            </tr>';
$managed->settings['itemview']['format']['odd_row_format'] = '
                                            <tr><td width="30"><input name="chb[x_#ID______]" value="" type="checkbox"></td>
                                              <td>{date:y-m-d H#:i:{_#DATE____}}</td>
                                              <td>_#QUERY___</td>
                                              <td>_#FOUND_CO</td>
                                              <td>_#SEARCH_T</td>
                                              <td>_#ADDITION</td>
                                              <td>_#ID______</td>
                                              <td>_#USER____</td>
                                           </tr>';

$apage = new AA_Adminpageutil('aaadmin', 'searchlog');
$apage->setTitle(_m("View SearchLog"));
$apage->printWithManager((new Manager('searchlog', $managed))->setAllModules());

