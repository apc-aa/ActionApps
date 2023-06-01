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

use AA\Util\AbstractAliasgenerator;

require_once __DIR__ . '/../../../include/mgettext.php';
require_once __DIR__ . '/../../../include/varset.php';

// Miscellaneous utility functions for the module

/** cached polls aliases
 *  @usage new AA_Aliases(new AA_Aliasgenerator_Polls())
 */
class AA_Aliasgenerator_Polls extends AbstractAliasgenerator {

    public static function getAliasesFromParams(array $params): AA_Aliases {
        $aliases = new AA_Aliases;

        $aliases->addAlias(new AA_Alias('_#POLL_ID_', 'f_1', 'id', _m('Poll ID')));
        $aliases->addAlias(new AA_Alias('_#MODULEID', 'f_n', 'module_id', _m('Module ID')));
        $aliases->addAlias(new AA_Alias('_#QUESTION', 'f_1', 'headline', _m('Prints poll question')));
        $aliases->addAlias(new AA_Alias('_#PUB_DATE', 'f_d:n/j/Y', 'publish_date', _m('Publish Date')));
        $aliases->addAlias(new AA_Alias('_#EXP_DATE', 'f_d:n/j/Y', 'expiry_date', _m('Expiry Date')));
        $aliases->addAlias(new AA_Alias('_#PARAMS__', 'f_1', 'params', _m('Prints poll params')));
        $aliases->addAlias(new AA_Alias('_#EDITPOLL', 'f_e:poll_edit', 'id', _m('Link to poll editing page (for admin interface only)')));

        return $aliases;
    }
}
/** cached polls answer aliases
 *  @usage new AA_Aliases(new AA_Aliasgenerator_Answer())
 */
class AA_Aliasgenerator_Answer extends AbstractAliasgenerator {

    public static function getAliasesFromParams(array $params): AA_Aliases {
        $aliases = new AA_Aliases;

        $aliases->addAlias(new AA_Alias('_#QUESTION', 'f_t:{poll:{_#POLL_ID_}:_#QUESTION}', '', _m('Prints poll question')));
        $aliases->addAlias(new AA_Alias('_#POLLQUES', 'f_t:{poll:{_#POLL_ID_}:_#QUESTION}', '', _m('Prints poll question')));  // @deprecated
        $aliases->addAlias(new AA_Alias('_#PUB_DATE', 'f_t:{poll:{_#POLL_ID_}:_#PUB_DATE}', '', _m('Poll publish date')));
        $aliases->addAlias(new AA_Alias('_#EXP_DATE', 'f_t:{poll:{_#POLL_ID_}:_#EXP_DATE}', '', _m('Poll expiry date')));
        $aliases->addAlias(new AA_Alias('_#PARAMS__', 'f_t:{poll:{_#POLL_ID_}:_#PARAMS__}', '', _m('Poll params')));
        $aliases->addAlias(new AA_Alias('_#MODULEID', 'f_t:{poll:{_#POLL_ID_}:_#MODULEID}', '', _m('Module ID')));

        $aliases->addAlias(new AA_Alias('_#POLL_ID_', 'f_1', 'poll_id', _m('Poll id (32 characters hexadecimal number - the same for all answers)'))); // generated automatically form the table column using metabase methods

        $aliases->addAlias(new AA_Alias('_#ANS_NO__', 'f_1', 'priority', _m('Nubmer of answer')));
        $aliases->addAlias(new AA_Alias('_#ANS_VOTE', 'f_1', 'votes', _m('Nubmer of votes for this answer')));
        $aliases->addAlias(new AA_Alias('_#ANSWER__', 'f_1', 'answer', _m('Text of answer'))); // generated automatically form the table column using metabase methods
        $aliases->addAlias(new AA_Alias('_#ANS_ID__', 'f_1', 'id', _m('ID of answer (32 characters hexadecimal number)')));
        $aliases->addAlias(new AA_Alias('_#ANS_PERC', 'f_t:{poll_share}', '', _m('Votes for this answer in percent. You can use also {poll_share}.')));
        $aliases->addAlias(new AA_Alias('_#ANS_SUM_', 'f_t:{poll_sum}', '', _m('Sum of all votes. You can use also {poll_sum}.')));

        return $aliases;
    }
}


