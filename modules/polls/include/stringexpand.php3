<?php

/**
* Polls module is based on Till Gerken's phpPolls version 1.0.3. Thanks!
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
* @author    Pavel Jisl <pavel@cetoraz.info>, Honza Malik <honza.malik@ecn.cz>
* @license   http://opensource.org/licenses/gpl-license.php GNU Public License
* @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
* @link      https://www.apc.org/ APC
*
*/

/** Expands {poll:<poll_ids>:<aa_expression>} and displays the aa_expression
 *  for the poll
 *  @param $poll_id       - id of the poll (not poll module, but poll as one question)
 *  @param $aa_expression - field from 'polls' table or any other expression
 */
class AA_Stringexpand_Poll extends AA_Stringexpand {

    /** expand function
     * @param $ids_string
     * @param $expression
     * @return string
     */
    function expand($ids_string='', $expression='') {
        $ids     = explode_ids($ids_string);
        $results = [];
        foreach ( $ids as $poll_id ) {
            if ( $poll_id ) {
                $poll = AA_Polls::getPoll($poll_id);
                if ($poll) {
                    if ($expression) {
                        $results[$poll_id] = $poll->unalias($expression);
                    }
                }
            }
        }
        return join('',$results);
    }
}

/** Expands {poll_share[:<max>]} number representing current share of the votes
 *  for the answer. By default in scale of 0-100, so it could be used as percent
 *  value. You can specify the max parameter, so the values could be from 0 to ""
 *  $max which could be used as image width, ...
 *  @param $max
 */
class AA_Stringexpand_Poll_Share extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $max - maximum the votes for the answer could reach, default is 100
     * @return float|int
     */
    function expand($max='') {
        $poll     = AA_Polls::getPoll($this->item->getVal('poll_id'));
        $sum      = $poll->getVotesSum();
        $quotient = $max ? $max : 100;

        return $sum==0 ? 0 : round(($this->item->getVal('votes')/$sum) * $quotient);
    }
}

/** Expands {poll_sum} with number of all votes in this poll */
class AA_Stringexpand_Poll_Sum extends AA_Stringexpand_Nevercache {

    /** expand function - number of all votes in this poll */
    function expand() {
        $poll     = AA_Polls::getPoll($this->item->getVal('poll_id'));
        return $poll->getVotesSum();
    }
}


