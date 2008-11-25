<?php
/**
* Polls module is based on Till Gerken's phpPolls version 1.0.3. Thanks!
*
* PHP versions 4 and 5
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
* @link      http://www.apc.org/ APC
*
*/

require_once AA_INC_PATH. "mgettext.php3";
require_once AA_INC_PATH. "varset.php3";

// Miscellaneous utility functions for the module

/** Predefined aliases for polls. For another aliases use 'inline' aliases. */
function GetAnswerAliases() {
    $metabase = AA_Metabase::singleton();
    $aliases = array (
        "_#QUESTION" => GetAliasDef( "f_t:{poll:{_#POLL_ID_}:_#QUESTION}",   "", _m('Prints poll question')),
        "_#POLLQUES" => GetAliasDef( "f_t:{poll:{_#POLL_ID_}:_#QUESTION}",   "", _m('Prints poll question')),  // deprecated
        "_#PUB_DATE" => GetAliasDef( "f_t:{poll:{_#POLL_ID_}:_#PUB_DATE}",   "", _m('Poll publish date')),
        "_#EXP_DATE" => GetAliasDef( "f_t:{poll:{_#POLL_ID_}:_#EXP_DATE}",   "", _m('Poll expiry date')),
        "_#PARAMS__" => GetAliasDef( "f_t:{poll:{_#POLL_ID_}:_#PARAMS__}",   "", _m('Poll params')),
        "_#MODULEID" => GetAliasDef( "f_t:{poll:{_#POLL_ID_}:_#MODULEID}",   "", _m('Module ID')),

        "_#POLL_ID_" => GetAliasDef( "f_t",           "poll_id",                _m('Poll id (32 characters hexadecimal number - the same for all answers)')), // generated automaticaly form the table column using metabase methods

        "_#ANS_NO__" => GetAliasDef( "f_t",          "priority",                _m('Nubmer of answer')),
        "_#ANS_VOTE" => GetAliasDef( "f_t",             "votes",                _m('Nubmer of votes for this answer')),
        "_#ANSWER__" => GetAliasDef( "f_t",            "answer",                _m('Text of answer')), // generated automaticaly form the table column using metabase methods
        "_#ANS_ID__" => GetAliasDef( "f_t",                "id",                _m('ID of answer (32 characters hexadecimal number)')),
        "_#ANS_PERC" => GetAliasDef( "f_t:{poll_share}",     "",                _m('Votes for this answer in percent. You can use also {poll_share}.')),
        "_#ANS_SUM_" => GetAliasDef( "f_t:{poll_sum}",       "",                _m('Sum of all votes. You can use also {poll_sum}.')),
    );
//    return array_merge($metabase->generateAliases('polls_answer'), $aliases);
    return $aliases;
}

/** Predefined aliases for polls. For another aliases use 'inline' aliases. */
function GetPollsAliases() {  // function - we need trnslate _m() on use (not at include time)
    $metabase = AA_Metabase::singleton();
    $aliases = array (
        "_#POLL_ID_" => GetAliasDef( "f_t",               "id",           _m('Poll ID')),
        "_#MODULEID" => GetAliasDef( "f_n",               "module_id",    _m('Module ID')),
        "_#QUESTION" => GetAliasDef( "f_t",               "headline",     _m('Prints poll question')),
        "_#PUB_DATE" => GetAliasDef( "f_d:n/j/Y",         "publish_date", _m('Publish Date')),
        "_#EXP_DATE" => GetAliasDef( "f_d:n/j/Y",         "expiry_date",  _m('Expiry Date')),
        "_#PARAMS__" => GetAliasDef( "f_t",               "params",       _m('Prints poll params')),
        "_#EDITPOLL" => GetAliasDef( "f_e:poll_edit",     "id",           _m('Link to poll editing page (for admin interface only)')),
    );
    return $aliases;
    // array_merge($metabase->generateAliases('polls'), $aliases);
}

function printAliases() {
    $aliases = GetPollsAliases();
    echo "<center><table>";
    PrintAliasHelp($aliases);
    echo "</table></center>";
}

?>
