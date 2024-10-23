<?php
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

use AA\Manager;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/item.php";
require_once __DIR__ . "/../include/feeding.php";

if (!IfSlPerm(PS_FEEDING)) {
    MsgPage(_m("You have not permissions to change feeding setting"));
    exit;
}

// if ($mode === "test") { Feed::onefeed($testfeed["feed_id"], $testfeed, 5, 'write'); }

$managed = GetFeedManaged();

$apage = new AA_Adminpageutil('sliceadmin', 'rssfeeds');
$apage->setTitle(_m("Remote RSS Feed administration"));

$manager = new Manager('rssfeeds', $managed);

$manager->setRestrictionConds([
    new AA_Condition('slice_id', '==', pack_id(AA::$module_id))
]);

$apage->printWithManager($manager);
