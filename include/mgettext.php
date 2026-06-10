<?php
/**
 * Usage of the mini-gettext system.
 * See include/lang/readme.html for more info; lang files are maintained
 * by the misc/mgettext/cli.php generator.
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
 * @package   MiniGetText
 * @version   $Id$
 * @author    Jakub Adamek, Econnect, January 2003
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\Util\Langs;

/** bind_mgettext_domain function
 *  Reads language constants from given file/
 *   @param string $filename  in include/lang directory
 *   @param bool $cache     Should the old language constants remain in memory?
 *                     You will need this behaviour only when using a script which
 *                     several times change the language,
 *                     e.g. sends a lot of emails in different languages.
 *   @param string $lang      If you want to include several language files,
 *                     you must tell mgettext not too free the
 *                     translations from the previous lang file. You do so by
 *                     sending the language shortcut.
 */
function bind_mgettext_domain($filename, $cache = false, $lang = ""): void
{
    global $_m, $mgettext_lang, $mgettext_domain;     //    $mgettext_lang must be here for some reason... hm-2020-08-10

    static $_m_backup = [];

    $filename = __DIR__.'/lang/'.basename(str_replace('.php3','.php',$filename)); // basename: the name may come from DB (module.lang_file) - prevent path traversal; .php3 from old configs

    if ( $mgettext_domain == $filename ) {
        return;                             // allready loaded
    }

    // store strings into backup and look for new strings in backup
    if ($mgettext_domain && empty($_m_backup[$mgettext_domain]) && $cache) {
        $_m_backup[$mgettext_domain] = $_m;
    }

    $mgettext_domain = $filename;
    if ($cache) {
        $_m = $_m_backup[$mgettext_domain] ?? null;
        if ($_m) {
            return;
        }
    }

    if ( !is_file($filename)) {
        echo "<h1>WRONG MGETTEXT DOMAIN $filename</h1>";
    } else {
        if ($lang != Langs::currentLang()) {
            $_m = [];
        }
        $ret = include $filename;
        if (is_array($ret)) {
            // generated `return [...]` format (OPcache-shared constant array);
            // same-language section files accumulate, the newest one wins on duplicates
            $_m = (is_array($_m) && $_m) ? ($ret + $_m) : $ret;
        }
        // legacy format files assign $_m[...] themselves and return 1
    }
}

function mgettext_bind($lang, $section, $cache=false): void
{
    bind_mgettext_domain( $lang.'_'.$section.'_lang.php', $cache, $lang);
}

/** _m function
 *  Translates given message.
 *
 * @param string $id         Text to be translated. Escape % by backslash (\%).
 * @param array  $params     You may use %1,%2,... in $id and supply an array of params,
 *                           which are substituted for %i, e.g.
 *                           _m("Hello %1, how are you?", [$username])
 * @return string if translation in the active language (get_mgettext_lang()) does not yet exist,
 *                           returns $id, i.e. the English version
 */
function _m($id, $params=[]): string {
    global $_m;

    if (!strlen($id)) {
        return '';
    }

    //static $count = 0;
    //$arr = ['<hr>', ++$count, $id, $_m[$id], $GLOBALS['mgettext_domain']];
    //array_walk($arr, function ($v) { echo "<br>".$v;});

    $retval = $_m ? (string)($_m[$id] ?? '') : '';
    if (!$retval) {
        $retval = (string)$id;
    }

    if (!$params || !is_array($params)) {
        return $retval;
    }

    $s = ['\%'];
    $r = ['#$&*'];
    foreach ($params as $i => $iValue) {
        $s[] = '%'.($i+1);
        $r[] = $iValue;
    }
    $s[] = '#$&*';
    $r[] = '%';
    return str_replace($s, $r, $retval);
}

/** _mdelayed function
 *  Works the same as _m() but is not parsed by xmgettext. This way it is
 *   useful to translate a non-constant message, counted at run-time.
 * @param string $id
 * @param array  $params
 * @return string
 */
function _mdelayed($id, $params=[]): string {
    return _m($id, $params);
}
