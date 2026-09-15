<?php

/**
 * Sends Alerts messages with new items to users.
 * To be called directly or by Cron.
 * Parameter:
 *     $howoften
 *
 * @package Alerts
 * @version $Id$
 * @author Jakub Ad�mek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications
*/
/*
Copyright (C) 1999-2002 Association for Progressive Communications
https://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

use AA\Later\AlertsSweep;

if (!defined("AA_ID")) {
    require_once __DIR__ . "/../../include/config.php";
}
require_once __DIR__ . "/../../include/locsess.php";
require_once __DIR__ . "/../../include/util.php";
require_once __DIR__ . "/../../include/varset.php";

/** Manual run of one alerts sweep from the command line - the periodic ones
 *  are AA\Later\AlertsSweep tasks in the queue. Parameters
 *  howoften=instant|daily|weekly|twoweeks|monthly and fix=1 (only the
 *  collections not processed within their period):
 *   # cd /var/www/example.org/apc-aa/modules/alerts && nice php alerts.php howoften=weekly
 *  Not over HTTP any more: until 2026-09 cron.php fetched it that way, which
 *  let anyone trigger a mail-sending sweep.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$args = [];
parse_str(implode('&', $_SERVER['argv'] ?? []), $args);
$sweep = new AlertsSweep((string) ($args['howoften'] ?? ''), !empty($args['fix']));
echo safe($sweep->toexecutelater());
