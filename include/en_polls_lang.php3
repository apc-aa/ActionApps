<?php
/**
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

if (!defined ("EN_POLLS_LANG_INCLUDED")) {
      define ("EN_POLLS_LANG_INCLUDED",1);
}
else {
    return;
}

// config file identifier
// must correspond with this file name
define("LANG_FILE", "en_polls_lang.php3");

// HTML begin of admin page
// You should set language of admin pages and possibly any meta tags
define("HTML_PAGE_BEGIN",
 '<!DOCTYPE html public "-//W3C//DTD HTML 4.0 Transitional//EN">
  <html>
  <head>
  <link rel="StyleSheet" href="'.AA_INSTAL_PATH.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');


define("L_EDITOR_TITLE", "Polls administration");
define("L_ADMIN_POLLS_SETTINGS","Polls settings");
define("L_ADMIN_POLLS_Design","Polls design");

$L_MONTH = [
    1 => 'Leden', '�nor', 'B�ezen', 'Duben', 'Kv�ten', '�erven',
                '�ervenec', 'Srpen', 'Z���', '��jen', 'Listopad', 'Prosinec'
];

?>
