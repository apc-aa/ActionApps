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
use AA\Util\Langs;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/item.php";
require_once __DIR__ . "/../include/actions.php";

if ( !IsSuperadmin() ) {
    MsgPage(_m("You do not have permission to manage Emails"));
    exit;
}

/*  @todo - test and map needs to be implemented from old version
if ($mode == "map") {
$feed = FeedUtil::name2rssfeed(AA::$module_id, $sel_rssfeed_name);
go_url(StateUrl(self_base() . "se_mapping.php?from_slice_id=".$feed["remote_slice_id"]));
}
if ($mode == "test") {
Feed::onefeed($testfeed["feed_id"], $testfeed, 5, 'write');
}
*/



$managed = AA::Metabase()->getManagerConf('rssfeeds');
$managed->setColumns2show(['name','server_url','feed_id']);

$managed->setModule(AA::$module_id);
// $managed->setWidgetSetting('slice_id', ['enum'=>AA_Module::getUserModules(), 'name'=>_m('Owner')]);
$managed->setWidgetSetting('slice_id', ['type'=>'hidden', 'name'=>_m('Owner')]);
$managed->setWidgetSetting('feed_id',                                       ['name'=>_m('ID')]);
$managed->setWidgetSetting('server_url',                                    ['name'=>_m('URL of the feed'), 'help'=> _m("e.g. https://www.someplace.com/rss/index.xml").'<br>'._m("Do NOT change this URL if you already set the Mapping (or update mapping after the change). The mapping is tied to this url!")]);
$managed->setWidgetSetting('name',                                          ['name'=>_m('RSS Feed name')]);

$apage = new AA_Adminpageutil('sliceadmin', 'rssfeeds');
$apage->setTitle(_m("Remote RSS Feed administration"));

$manager = new Manager('rssfeeds', $managed);

$manager->setRestrictionConds([
    new AA_Condition('slice_id', '==', pack_id(AA::$module_id))
]);

$apage->printWithManager($manager);
