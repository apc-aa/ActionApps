<?php

/**
* little script for registering vote.
* used as workaround for setting cookies because while the script
* is SSI included to html, we can't set cookies!
*
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
* @author    Pavel Jisl, Honza Malik <honza.malik@ecn.cz>
* @license   http://opensource.org/licenses/gpl-license.php GNU Public License
* @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
* @link      http://www.apc.org/ APC
*
*/


require_once "../../include/config.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."mgettext.php3";
require_once "include/util.php3";

$encap = ( ($encap=="false") ? false : true );
require_once AA_INC_PATH. ($encap ? "locsessi.php3" : "locsess.php3");

$db = new DB_AA;

if (isset($vote) && isset($poll_id) && isset($redir)) {
    $poll = getValuesForPoll($id, $poll_id);
    registerVote($poll, $vote);
    header("Location: ".$redir);
} else {
    echo _m("Illegal vote !!!");
    exit;
}
?>
