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
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\Import\Definition;
use AA\Manager;

require_once __DIR__ . "/../include/init_page.php";

// imports fill slices only - a site or alerts admin holds the feeding right too
if (!Definition::mayImportInto(AuthGetUid(), (string) AA::$module_id)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permissions to manage the imports of this slice"), "admin");
    exit;
}

$apage = new AA_Adminpageutil('sliceadmin', 'dataimports');
$apage->setTitle(_m('Imports'));
$apage->setSubtitle(_m("An import fills the slice with the data of another source, now or by a planned task. Run starts it; the import runs on in the background and its detail shows how it goes."));
$apage->printWithManager(new Manager('dataimport' . AA::$module_id, Definition::getManagerConf()));
