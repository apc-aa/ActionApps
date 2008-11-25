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

/**
* Polls for ActionApps
*
* Based on phpPolls 1.0.3 from http://phpwizards.net (also under GPL v2.)
* You can supply just pid parameter - the first poll is displayed
* (the one in Active bin with oldest publish date and not expired)
*
* @param pid      - id of poll module
* @param poll_id  - 32 digit long hexadecimal id of poll (see Polls Admin)
* @param vote_id  - vote for specified answer
* @param conds    - conds for the module which enables to display polls matching
*                   specified condition (just like for views)
* @param sort     - sortorder of polls (just like for views)
* @param listlen  - number of polls displayed - default is 1
*/


require_once "../../include/config.php3";
require_once AA_INC_PATH. "util.php3";
require_once AA_INC_PATH. "mgettext.php3";
require_once AA_INC_PATH. "searchlib.php3";
require_once AA_INC_PATH. "item.php3";
require_once AA_INC_PATH. "itemview.php3";
require_once AA_BASE_PATH."modules/polls/include/util.php3";
require_once AA_BASE_PATH."modules/polls/include/stringexpand.php3";
require_once AA_BASE_PATH."modules/polls/include/poll.class.php3";

/**
 * Handle with PHP magic quotes - quote the variables if quoting is set off
 * @param mixed $value the variable or array to quote (add slashes)
 * @return mixed the quoted variables (with added slashes)
 */
function StripslashesDeep($value) {
    return is_array($value) ? array_map('StripslashesDeep', $value) : stripslashes($value);
}

if ( get_magic_quotes_gpc() ) {
    $_POST    = StripslashesDeep($_POST);
    $_GET     = StripslashesDeep($_GET);
    $_REQUEST = StripslashesDeep($_REQUEST);
    $_COOKIE  = StripslashesDeep($_COOKIE);
}

add_vars('', '_REQUEST');

$encap = ( ($encap=="false") ? false : true );
require_once AA_INC_PATH."locsess.php3";

$db = new DB_AA;

if (isset($_REQUEST['vote_id']) AND isset($_REQUEST['poll_id'])) {
    $poll = AA_Polls::getPoll($_REQUEST['poll_id']);
    $poll->registerVote($_REQUEST['vote_id']);
}

if ($_REQUEST['poll_id']) {
    // we want to display specified poll, or we just voted
    $poll_zids = new zids($_REQUEST['poll_id']);
} else {
    $set       = AA_Poll::generateSet($_REQUEST['pid'],$_REQUEST['conds'],$_REQUEST['sort']);
    $poll_zids = AA_Metabase::queryZids(array('table'=>'polls'), $set);
    $poll_zids = $poll_zids->slice(0, $_REQUEST['listlen'] ? $_REQUEST['listlen'] : 1);
}

// and now display the polls
$zid_count = $poll_zids->count();

for ( $i=0; $i < $zid_count; $i++ ) {
    $poll   = AA_Polls::getPoll($poll_zids->id($i));
    $design = $_REQUEST['design_id'] ? $_REQUEST['design_id'] : ($_REQUEST['vote_id'] ? 'aftervote' : 'beforevote');
    $poll->display($design);
}

exit;
?>
