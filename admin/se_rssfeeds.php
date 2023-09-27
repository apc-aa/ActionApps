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
require_once __DIR__ . "/../include/actions.php";

if ( !IsSuperadmin() ) {
    MsgPage(_m("You do not have permission to manage Emails"));
    exit;
}

// if ($mode == "test") { Feed::onefeed($testfeed["feed_id"], $testfeed, 5, 'write'); }

$buttons  = [
    'Map'      => a_href(get_admin_url('se_mapping.php', ['rssfeed_id={feed_id}']), _m('Map'), ['class' => 'aa-button']),
    'Queue'    => a_href(get_admin_url('xmlclient.php',  ['rssfeed_id={feed_id}']), _m('Run (queue)'), ['class' => 'aa-button-run']),
    'Test'     => a_href(get_admin_url('xmlclient.php',  ['rssfeed_id={feed_id}&fill=0']), _m('Test'), ['class' => 'aa-button-test']),
    'Source'   => a_href(get_admin_url('xmlclient.php',  ['rssfeed_id={feed_id}&display=1']), _m('Source'), ['class' => 'aa-button-show']),
    'Validate' => a_href('https://validator.w3.org/feed/check.cgi?url={safe:{server_url}}', _m('Validate'), ['class' => 'aa-button-test']),
];

$managed = AA::Metabase()->getManagerConf('rssfeeds',null,null,[],$buttons);
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
