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

if (!IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permissions to see the imports of all slices"), "admin");
    exit;
}

$apage = new AA_Adminpageutil('aaadmin', 'dataimports');
$apage->setTitle(_m('Imports of all slices'));
$apage->setSubtitle(_m("The imports of every slice of this installation. A new import is added on the Imports page of its slice."));
$apage->addRequire(get_aa_url('css/dataimport.css', ['v' => AA_JS_VERSION], false));
// a definition added here would belong to whichever slice is selected now
$apage->printWithManager((new Manager('dataimport', Definition::getManagerConf()->setAction('add', false)))->setAllModules());
