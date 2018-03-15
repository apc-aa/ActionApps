<?php
/**
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
 * @package   Include
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

/** @param id  id of AA_Exportsetings object stored on admin/se_export.php page
 *
 *  You can define export to CVS, HTML, XLS on the Admin -> Export file page
 *  and then call the the stored export by the id on /export.php?id=... page.
 *  You can specify the conditions for the export, the bins, the sorting
 *  and the name of outputfile. In all the settings you can use
 *  the {AA expressions}, so it is quite easy to make the export with changeable
 *  conditions (for example if you put "d-year............-=-{qs:year}", then
 *  you will be able to call it like /apc-aa/export.php?id=5357...&year=2012)
 *  */

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
    $_COOKIE  = StripslashesDeep($_COOKIE);
}

require_once "./include/config.php3";
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."item.php3";
require_once AA_INC_PATH."searchlib.php3";
require_once AA_INC_PATH."locsess.php3";    // DB_AA object definition

require_once AA_INC_PATH."discussion.php3";
require_once AA_INC_PATH."exporter.class.php3";

if (!$_GET['id']) {
    echo 'No export id defined';
    exit;
}

$exportset = AA_Object::load($_GET['id'], 'AA_Exportsetings');

if (is_null($exportset)) {
    echo 'Bad export id - '. $_GET['form_id'];
    exit;
}

$exportset->export();

page_close();
exit;


