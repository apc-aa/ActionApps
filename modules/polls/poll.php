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


require_once __DIR__."/../../include/config.php";
require_once __DIR__ . "/../../include/util.php";
require_once __DIR__ . "/../../include/mgettext.php";
require_once __DIR__ . "/../../include/searchlib.php";
require_once __DIR__ . "/../../include/item.php";
require_once __DIR__ . "/../../include/itemview.php";
require_once __DIR__ . "/../../modules/polls/include/util.php";
require_once __DIR__ . "/../../modules/polls/include/stringexpand.php";

// special case - if we want something like view for polls, so we want to be
// constant and not to react to URL parameters, then we add &lock=1 as parameter
// to SSI include
if (empty($_REQUEST['lock'])) {
    add_vars('', '_REQUEST');
}

$encap = ( array_key_exists('encap', $_REQUEST) AND ($_REQUEST['encap']=="false") ) ? false : true;

require_once __DIR__ . "/../../include/locsess.php";

echo AA_Poll::processPoll($_REQUEST);

exit;

