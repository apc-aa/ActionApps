<?php
/**
 * Usage of the mini-gettext system.
 * See include/lang/readme.html for more info, and misc/mgettext for scripts
 * used to maintain the language files.
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
 * @package   MiniGetText
 * @version   $Id$
 * @author    Jakub Adamek, Econnect, January 2003
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

/** get_mgettext_lang function
 * Returns current language (two-letter acronym, e.g. "es", "cz").
 */
function get_mgettext_lang() {
    global $mgettext_lang;
    return isset($mgettext_lang) ? $mgettext_lang : 'en';
}

/** bind_mgettext_domain function
 *  Reads language constants from given file/
 *   @param $filename  full path
 *   @param $cache     Should the old language constants remain in memory?
 *                     You will need this behaviour only when using a script which
 *                     several times changes the language,
 *                     e.g. sends a lot of emails in different languages.
 *   @param $lang      If you want to include several language files,
 *                     you must tell mgettext not too free the
 *                     translations from the previous lang file. You do so by
 *                     sending the language shortcut.
 */
function bind_mgettext_domain($filename, $cache = false, $lang = "") {
    global $_m, $mgettext_lang, $mgettext_domain;

    if ( $mgettext_domain == $filename ) {
        return;                             // allready loaded
    }

    // store strings into backup and look for new strings in backup
    if (!$_m_backup[$mgettext_domain] && $cache) {
        $_m_backup[$mgettext_domain] = $_m;
    }

    $mgettext_domain = $filename;
    if ($cache) {
        $_m = $_m_backup[$mgettext_domain];
        if ($_m) return;
    }

    if ( !is_file($filename)) {
        echo "<h1>WRONG MGETTEXT DOMAIN $filename</h1>";
    } else {
        if ($lang != get_mgettext_lang()) {
            $_m = "";
        }
        include $filename;
    }
}

function mgettext_bind($lang, $section, $cache=false) {
    bind_mgettext_domain(AA_INC_PATH.'lang/'.$lang.'_'.$section.'_lang.php3', $cache, $lang);
}

/** _m function
 *  Translates given message.
 *
 *   @param string $id       Text to be translated.
 *   @param array $params    You may use %1,%2,... in $id and supply an array of params,
 *                           which are substituted for %i, e.g.
 *                           _m("Hello %1, how are you?",array($username))
 *   @return  if translation in the active language (get_mgettext_lang()) does not yet exist,
 *                 returns $id, i.e. the English version
 */
/** _m function
 *  Translates given message.
 *
 *   @param string $id       Text to be translated. Escape % by backslash (\%).
 *   @param array $params    You may use %1,%2,... in $id and supply an array of params,
 *                           which are substituted for %i, e.g.
 *                           _m("Hello %1, how are you?",array($username))
 *   @return  if translation in the active language (get_mgettext_lang()) does not yet exist,
 *                 returns $id, i.e. the English version
 */
function _m($id, $params = 0) {
    global $_m;

    $retval = $_m[$id];
    if (!$retval) {
        $retval = $id;
    }

    if (!is_array($params)) {
        return $retval;
    }

    $s = array('\%');
    $r = array('#$&*');
    for ( $i=0, $ino=count($params); $i<$ino; ++$i) {
        $s[] = '%'.($i+1);
        $r[] = $params[$i];
    }
    $s[] = '#$&*';
    $r[] = '%';
    return str_replace($s, $r, $retval);
}

/** _mdelayed function
 *  Works the same as _m() but is not parsed by xmgettext. This way it is
 *   useful to translate a non constant message, counted at run-time.
 */
function _mdelayed($id, $params = 0) {
    return _m($id, $params);
}

/** returns lang code ('cz', 'en', 'en-utf8', 'de',...) from given $lang_file or from default
 */
function GetLang($lang_file) {
    $lang_code = substr($lang_file, 0, strpos($lang_file,'_'));
    return isset($GLOBALS['LANGUAGE_NAMES'][$lang_code]) ? $lang_code : substr(DEFAULT_LANG_INCLUDE, 0, strpos(DEFAULT_LANG_INCLUDE,'_'));
}

?>
