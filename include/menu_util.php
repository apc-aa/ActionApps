<?php

use AA\IO\DB\DB_AA;
use AA\Util\Langs;

/** This script shows the top menu (navigation bar) and second level menu (left bar)
 *   by the function showMenu ($activeMain, $activeSubmenu = "", $showMain = 1, $showSub = 1).
 *
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
 * @author    Jakub Adamek
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 * @package   Include
 * @version   $Id$
 */


/**
 * @param AA_Profile $profile
 * @return array
 */
function GetCommonMenu(AA_Profile $profile): array {
    global $r_state;

    $menu = [];

    $menu["aaadmin"] = [
        "label"   => GetLabel($profile,'ui_manager','top_aaadmin', _m("AA")),
        "title"   => _m("AA Administration"),
        "href"    => "admin/aafinder.php",
        "cond"    => IsSuperadmin() && $profile->getProperty('ui_manager', 'top_aaadmin') !== '',
        "level"   => "main",
        "submenu" => "aaadmin_submenu"
    ];

    $menu["aaadmin_submenu"] = [
        "bottom_td" => 300,
        "level"     => "submenu",
        "items"     => [

        "header0"     => _m("Slices / Modules"),
        "sliceadd"    => ["label"=>_m("Create new"),      "cond"=>IfSlPerm(PS_ADD),      'ico'=>'ico-new-slice.png', "href"=>"admin/sliceadd.php"],
        //"slicewiz"    => ["label"=>_m("Create new Wizard"), "cond"=>IfSlPerm(PS_ADD),    "href"=>"admin/slicewiz.php"],
        "slicedel"    => ["label"=>_m("Delete"),          "cond"=>IsSuperadmin(),        "href"=>"admin/slicedel.php"],
        "jumpedit"    => [
            // Jump is a legacy module: hide entirely on installs that have never used it
            "label"=>_m("Edit Jump"),       "cond"=>IfSlPerm(PS_ADD) && DB_AA::test('module', [['type','J'],['deleted',0,'i']]),      "exact_href" =>
                       StateUrl(AA_INSTAL_PATH."modules/jump/modedit.php?edit=1")
        ],
    /*    "delete" => array ("label" => _m("Empty trash"), "cond"=>IfSlPerm(PS_DELETE_ITEMS), "href"=>"admin/index.php?Delete=trash"),*/

        "header1"     => ['label'=>_m("Permissions"), 'ico'=>'ico-permissions.png'],
        "u_edit"      => ["label"=>_m("Users"),           "cond"=>IfSlPerm(PS_NEW_USER), "href"=>"admin/um_uedit.php"],
        "g_edit"      => ["label"=>_m("Groups"),          "cond"=>IfSlPerm(PS_NEW_USER), "href"=>"admin/um_gedit.php"],

        "header9"     =>_m("Misc"),
        "toexecute"   => ["label"=>_m("Task Manager"),    "cond"=>IsSuperadmin(),        "href"=>"admin/aa_toexecute.php"],
        "tasks"       => ["label"=>_m("Planned Tasks"),   "cond"=>IsSuperadmin(),        "href"=>"admin/aa_tasks.php"],
        "cron"        => ["label"=>_m("Cron"),            "cond"=>IsSuperadmin(),        "href"=>"admin/aa_cron.php"],
        "log"         => ["label"=>_m("View Log"),        "cond"=>IsSuperadmin(),        "href"=>"admin/aa_log.php?srchbr_akce=1&srchbr_field%5B0%5D=time&srchbr_oper%5B0%5D=%3E&srchbr_value%5B0%5D=".(time()-8*24*3600)."&srchbr_order%5B0%5D=time&srchbr_order_dir%5B0%5D=on"],
        "searchlog"   => ["label"=>_m("View SearchLog"),  "cond"=>IsSuperadmin(),        "href"=>"admin/aa_searchlog.php"],
        "aafinder"    => ["label"=>_m("AA finder"),       "cond"=>IsSuperadmin(),        "href"=>"admin/aafinder.php"],
        'optimize'    => ["label"=>_m("Optimize"),        "cond"=>IsSuperadmin(),        "href"=>"admin/aa_optimize.php"],
        "statistics"  => ["label"=>_m("Statistics"),      "cond"=>IsSuperadmin(),        "href"=>"admin/aa_statistics.php"],
        "history"     => ["label"=>_m("History"),         "cond"=>IfSlPerm(PS_HISTORY),  "href"=>"admin/se_history.php"],
        "xmgettext"   => ["label"=>_m("Mgettext"),        "cond"=>IsSuperadmin(),        "exact_href"=>"../misc/mgettext/index.php"],
        "console"     => ["label" =>_m("Console"),        "cond" =>IsSuperadmin(),       "href" =>"admin/console.php"],

        "header8"     =>_m("Feeds"),
        "rsstest"     => ["label"=>_m("RSS test"),        "cond"=>IsSuperadmin(),        "href"=>"admin/rsstest.php"],
        "aarsstest"   => ["label"=>_m("AA RSS test"),     "cond"=>IsSuperadmin(),        "href"=>"admin/aarsstest.php"],
        "testrss"     => ["label"=>_m("Run feeding"),     "cond"=>IsSuperadmin(),        "href"=>"admin/xmlclient.php?debugfeed=4"],

        "header5"     =>_m("Slice structure"),
        "sliceexp"    => ["label"=>_m("Export"),          "cond"=>IfSlPerm(PS_ADD),      "href"=>"admin/sliceexp.php"],
        "sliceimp"    => ["label"=>_m("Import"),          "cond"=>IfSlPerm(PS_ADD),      "href"=>"admin/sliceimp.php"],

        "header7"     =>_m("Wizard"),
        //"te_wizard_welcome2"  => ["label"=>_m("Welcomes"),  "cond"=>IsSuperadmin(),        "href"=>"admin/tabledit.php?set_tview=email"],
        "te_wizard_welcome" => ["label"=>_m("Welcomes"),  "cond"=>IsSuperadmin(),        "href"=>"admin/aa_emails.php"],
        "te_wizard_template"=> ["label"=>_m("Templates"), "cond"=>IsSuperadmin(),        "href"=>"admin/aa_wizardtemplate.php"],

        "header10"     =>_m("Documentation"),
        "styleguide"  => ["label" =>_m("Style Guide"),    "cond" =>IsSuperadmin(),       "href" =>"doc/style-guide.html"],
    //    "oneoff" => array("label"=>_m("One Off Code"), "cond"=>IsSuperadmin(), "href"=>"admin/oneoff.php"),
        ]
    ];

    $menu["central"] = [
        "label"   => GetLabel($profile,'ui_manager','top_central', _m("Central")),
        "title"   => _m("AA Central"),
        "href"    => "central/index.php",
        "cond"    => IsSuperadmin() && $profile->getProperty('ui_manager', 'top_central') !== '',
        "level"   => "main",
        "submenu" => "central_submenu"
    ];

    $menu["central_submenu"] = [
        "bottom_td" => 200,
        "level"     => "submenu",
        "items"     => [
            "header1"     => _m("Folders"),
            "app"         => ["cond"=>1,                           "href"=>"central/index.php?Tab1=1",                                'ico'=>'ico-items.png', "label"=> _m("Active")." <span class='aa-bin-cnt'>". (int)$r_state['bin_cnt']['folder1'] ."</span>"],
            "hold"        => ["cond"=>1,                           "href"=>"central/index.php?Tab2=1",                                'ico'=>'ico-holdbin.png', "label"=>_m("Hold bin")." <span class='aa-bin-cnt'>". (int)$r_state['bin_cnt']['folder2'] ."</span>"],
            "trash"       => ["cond"=>1,                           "href"=>"central/index.php?Tab3=1",                                'ico'=>'ico-trashbin.png', "label"=>_m("Trash bin")." <span class='aa-bin-cnt'>". (int)$r_state['bin_cnt']['folder3'] ."</span>"],

            "header2"     => _m("Misc"),
            "synchronize" => ["cond"=>IsSuperadmin(),              "href"=>"central/synchronize.php",                                    "label"=>_m("Synchronize...")],
            "copyslice"   => ["cond"=>IsSuperadmin(),              "href"=>"central/copyslice.php",                                      "label"=>_m("Copy Slice...")],
            "item6"       => ["cond"=>IsSuperadmin(),              "href"=>"central/index.php?DeleteTrash=1",                           "label"=>_m("Empty trash"), "js"=>"EmptyTrashQuestion('{href}','"._m("Are You sure to empty trash?")."')"],
            "importcsv"   => ["cond"=>IsSuperadmin(),              "href"=>"central/import_csv.php",                                     "label"=>_m("Import conf from CSV...")],
        ]
    ];

    return $menu;
}


/**
 * @param  AA_Profile   $profile
 * @param  string       $property
 * @param  string       $selector
 * @param  string|null  $default_text
 * @param  string       $ico
 *
 * @return string
 */
function GetLabel(AA_Profile $profile, string $property, string $selector, ?string $default_text, string $ico=''): string {
    $val =  $profile->getProperty($property, $selector);
    $ret =  $ico ? GetAAImage($ico,'',23,23) : '';
    $ret .= ($val === false) ? $default_text : $val;
    return $ret;
}

// ----------------------------------------------------------------------------------------
/* creates a JavaScript variable modulesOptions, which allows to create another Module selectbox
    without reprinting all the options */
/** PrintModuleSelection function
 *
 */
function GetModuleSelection(): string {
    global $g_modules;
    $ret = '';

    $profile = AA_Profile::getProfile(AuthGetUid(), AA::$module_id); // current user settings

    if ( is_array($g_modules) && count($g_modules) > 1 && $profile->getProperty('ui_manager', 'top_moduleselection') === false) {
        // create the modulesOptions content:
        $permitted_slices = GetUserSlices();
        $modules_cond = ($permitted_slices==='all') ? [] : [['module.id', array_keys($permitted_slices), 'l']];

        $mymodules = DB_AA::select([],
            'SELECT LOWER(HEX(module.id)) AS unpackid, module.type, module.deleted, module.name, slice.type AS slice_type FROM `module` LEFT JOIN `slice` ON module.id=slice.id',
            $modules_cond,
            ['module.deleted','module.priority','module.name']);

        // do not print deleted modules when you are not admin of the slice or superadmin
        if ($permitted_slices !== 'all') {
            $mymodules = array_filter($mymodules, static fn($slice) => (!$slice['deleted'] || IfSlPerm(PS_FIELDS, $slice['unpackid'])));
        }

        // Partition modules: sites (W), regular slices (S, non-RM), and other types (RM, Polls, Alerts, ...).
        // Order inside each bucket follows the query order (deleted asc, priority asc, name asc).
        $sites         = [];   // unpackid => row
        $slices_by_id  = [];   // unpackid => row  (non-RM slices)
        $other_by_type = [];   // type => [row, ...]  (RM bucket uses key 'RM')
        foreach ($mymodules as $row) {
            if ($row['type'] === 'W') {
                $sites[$row['unpackid']] = $row;
            } elseif ($row['type'] === 'S' && $row['slice_type'] !== 'ReaderManagement') {
                $slices_by_id[$row['unpackid']] = $row;
            } else {
                $bucket = ($row['type'] === 'S') ? 'RM' : $row['type'];
                $other_by_type[$bucket][] = $row;
            }
        }

        // One batched query for site->slice memberships, scoped to visible sites & slices.
        // Wrapped in Contentcache for cross-request reuse (mirrors the canonical path in
        // AA_Module_Site::getRelatedModules()). Needs both directions, so we keep the
        // raw pair list rather than calling getRelatedModules() per site.
        $site2slices = [];
        $slice2sites = [];
        if (!empty($sites) && !empty($slices_by_id)) {
            $rels = AA::Contentcache()->get_result(['AA\IO\DB\DB_AA','select'], [[],
                'SELECT LOWER(HEX(source_id)) AS site_id, LOWER(HEX(destination_id)) AS slice_id FROM `relation`',
                [['source_id',      array_keys($sites),        'l'],
                 ['destination_id', array_keys($slices_by_id), 'l'],
                 ['flag',           REL_FLAG_MODULE_DEPEND,    'i']]]);
            foreach ($rels as $rel) {
                $site2slices[$rel['site_id']][$rel['slice_id']] = true;
                $slice2sites[$rel['slice_id']][$rel['site_id']] = true;
            }
        }

        // Pre-bucket slices under each site so the rendering loop is O(rels + slices)
        // rather than O(sites × slices). Preserves $slices_by_id's priority/name order.
        $slices_under_site = [];
        foreach ($slices_by_id as $slice_id => $slice_row) {
            foreach (array_keys($slice2sites[$slice_id] ?? []) as $site_id) {
                $slices_under_site[$site_id][$slice_id] = $slice_row;
            }
        }

        $mydir = AA_Module::getModuleInfo('url', AA::$module_id);

        /* Indent prefixes for fallback browsers. HTML entity (not raw U+00A0) so the
           bytes are valid in both UTF-8 and 8859-1 page encodings — a UTF-8 NBSP byte
           sequence renders as garbage on legacy 8-bit slice admin pages. */
        $nbsp4 = str_repeat('&nbsp;', 4);   // for orphan slices + non-slice modules
        $nbsp6 = str_repeat('&nbsp;', 6);   // for site-child slices (deeper level)

        /* Pick an icon code per module/slice. Falls through to slice_type for S-type rows.
           Slice-type literals must stay in sync with AA_Slice::getSliceTypes(). */
        $by_module_type = ['W' => 'site', 'RM' => 'reader', 'P' => 'polls',
                           'Alerts' => 'alerts', 'Links' => 'links', 'J' => 'jump'];
        $by_slice_type  = ['ReaderManagement' => 'reader', '___CodePage' => 'pages',
                           '___CodeView' => 'views', '___Constant' => 'constants',
                           '___Translate' => 'translate', '___Field' => 'field'];
        $iconFor = static function(array $row) use ($by_module_type, $by_slice_type): string {
            return $by_module_type[$row['type']]
                ?? $by_slice_type[$row['slice_type'] ?? '']
                ?? 'items';
        };

        /* Inline SVG <symbol> defs referenced via <use>. Kept single-line because the
           dropdown HTML is injected through innerHTML (see $js below) and embedded
           newlines would break the JS string. */
        $icon_symbols = [
            'site'      => '<symbol id="aa-ic-site" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="5.5"/><path d="M2.5 8h11"/><path d="M8 2.5c1.8 2 1.8 9 0 11M8 2.5c-1.8 2-1.8 9 0 11"/></symbol>',
            'reader'    => '<symbol id="aa-ic-reader" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="5.5" r="2.3"/><path d="M3 14c.7-2.5 2.8-3.8 5-3.8s4.3 1.3 5 3.8"/></symbol>',
            'constants' => '<symbol id="aa-ic-constants" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="3.5" width="11" height="9" rx="1"/><path d="M2.5 6.5h11M2.5 9.5h11M7 3.5v9"/></symbol>',
            'pages'     => '<symbol id="aa-ic-pages" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="square" stroke-linejoin="miter"><rect x="1" y="6" width="4" height="4"/><rect x="10" y="2" width="5" height="4"/><rect x="10" y="10" width="5" height="4"/><path d="M5 8H7.5M7.5 4V12M7.5 4H10M7.5 12H10"/></symbol>',
            'items'     => '<symbol id="aa-ic-items" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="3" cy="4" r=".7" fill="currentColor" stroke="none"/><circle cx="3" cy="8" r=".7" fill="currentColor" stroke="none"/><circle cx="3" cy="12" r=".7" fill="currentColor" stroke="none"/><path d="M5 4h8.5M5 8h8.5M5 12h8.5"/></symbol>',
            'views'     => '<symbol id="aa-ic-views" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="2.5" width="11" height="11" rx="1.2"/><path d="M6 6L4.5 8 6 10M10 6l1.5 2L10 10M8 5.5l-1 5"/></symbol>',
            'translate' => '<symbol id="aa-ic-translate" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h4M5 2.5V7"/><path d="M3 7c0-2 2-2 2-2s2 0 2 2"/><path d="M9 14L12 7l3 7M9.8 12h4.4"/></symbol>',
            'field'     => '<symbol id="aa-ic-field" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="square" stroke-linejoin="miter"><path d="M1.5 2h4"/><rect x="1.5" y="3.5" width="13" height="2.75"/><path d="M1.5 9h4"/><rect x="1.5" y="10.5" width="13" height="2.75"/></symbol>',
            'polls'     => '<symbol id="aa-ic-polls" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 4.5h10M2.5 8h7M2.5 11.5h4.5"/></symbol>',
            'alerts'    => '<symbol id="aa-ic-alerts" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4" width="11" height="8" rx="1"/><path d="M2.5 4.5L8 9l5.5-4.5"/></symbol>',
            'links'     => '<symbol id="aa-ic-links" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 11.3l-1.5 1.5a2.5 2.5 0 11-3.5-3.5l3-3a2.5 2.5 0 013.5 0"/><path d="M9.5 4.7l1.5-1.5a2.5 2.5 0 113.5 3.5l-3 3a2.5 2.5 0 01-3.5 0"/></symbol>',
            'jump'      => '<symbol id="aa-ic-jump" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><path d="M2 8h7.5"/><path d="M6.5 5l3 3-3 3"/><circle cx="12.5" cy="8" r="1.2" fill="currentColor" stroke="none"/></symbol>',
        ];

        /* Non-selectable section header — replaces the dropped <optgroup>. Firefox
           fallback shows the disabled option in default grey; modern browsers pick
           up .aa-section-header styling. The leading spacer option supplies vertical
           breathing room in the fallback path where option padding/margin is ignored;
           modern browsers hide it via CSS (display:none) and rely on real padding. */
        $emitSection = static function(string $label): string {
            // No aria-hidden on the spacer: Chrome blocks aria-hidden on <option>. The
            // disabled attribute alone suffices — AT skips non-interactive options.
            return '<option value="" class="aa-section-spacer" disabled>&nbsp;</option>'
                 . '<option value="" class="aa-section-header" disabled>' . safe($label) . '</option>';
        };

        /**
         * Emit one <option>.
         *  - $prefix is concatenated verbatim (no auto-space) so the caller can pad with
         *    leading characters/spaces that act as a fallback hierarchy cue in older
         *    browsers where the option class CSS won't apply.
         *  - $class adds a CSS hook for modern browsers (`appearance: base-select`).
         *  - $aria_desc fills `aria-description` to convey parent-site context to AT
         *    in lieu of the dropped <optgroup> grouping announcement.
         *  - $disabled emits a non-selectable header-style option (used when the user
         *    can see a site's child slices but lacks permission to the site itself).
         *  - $icon names a symbol in $icon_symbols; rendered via <use> after the label.
         *    Renders as empty text in old browsers, as a visible icon under base-select.
         */
        // Track whether the HTML 'selected' attribute has already been emitted: a slice
        // that lives under multiple sites would otherwise get 'selected' on every row,
        // causing a wrong-row flash before the localStorage JS reconciles.
        $selected_emitted = false;
        $emitOption = static function (string $id, string $name, bool $deleted,
                                       string $site_id = '', string $prefix = '',
                                       string $class = '', string $aria_desc = '',
                                       bool $disabled = false, string $icon = '') use ($mydir, &$selected_emitted): string {
            $mod_dir = AA_Module::getModuleInfo('url', $id);
            $val     = (($mydir === $mod_dir) ? '' : AA_INSTAL_PATH.$mod_dir) . "?module_id=$id";
            $label   = safe($name);
            if ($deleted) {
                $label = "&times;&times;&times; $label &times;&times;&times;";
            }
            // $prefix is raw HTML (callers pass empty string or repeated &nbsp; entities,
            // never user input) — kept unescaped so HTML entities survive intact.
            $label = $prefix . $label;
            if ($icon !== '') {
                $label .= '<svg class="aa-icon" viewBox="0 0 16 16" aria-hidden="true"><use href="#aa-ic-' . safe($icon) . '"/></svg>';
            }

            // label attribute carries the bare slice name (without NBSP indent or
            // SVG icon) so native typeahead matches on what the user reads, not on
            // the leading whitespace. The JS keydown handler below adds substring
            // matching on top of (or instead of) the browser's prefix typeahead.
            $opt = '<option value="' . safe($val) . '" label="' . safe($name) . '"';
            if ($class !== '') {
                $opt .= ' class="' . safe($class) . '"';
            }
            if ($site_id !== '') {
                $opt .= ' data-site-id="' . safe($site_id) . '"';
            }
            if ($aria_desc !== '') {
                $opt .= ' aria-description="' . safe($aria_desc) . '"';
            }
            if ($disabled) {
                $opt .= ' disabled';
            } elseif (!$selected_emitted && AA::$module_id == $id) {
                $opt .= ' selected';
                $selected_emitted = true;
            }
            return $opt . '>' . $label . '</option>';
        };

        $options = '';

        // 1) Sites + their slices, rendered as flat <option>s (no per-site <optgroup>).
        //    The site row is a styled "header-like" option:
        //      - selectable when the user can access the site
        //      - disabled-but-visible when the user only has perms to its child slices
        //        (preserves the grouping cue without misleading the user)
        //    Child slices follow immediately, indented in the option text for old-browser
        //    fallback, and via CSS padding-left in modern browsers.
        foreach ($sites as $site_id => $site_row) {
            $site_perm = IfSlPerm(PS_FIELDS, $site_id);
            $kids      = $slices_under_site[$site_id] ?? [];
            if (!$site_perm && empty($kids)) {
                continue;
            }
            $options .= $emitOption(
                $site_id, $site_row['name'], (bool)$site_row['deleted'],
                $site_id, '',
                $site_perm ? 'aa-site' : 'aa-site aa-site-readonly',
                '', !$site_perm, 'site'
            );
            foreach ($kids as $slice_id => $slice_row) {
                $options .= $emitOption(
                    $slice_id, $slice_row['name'], (bool)$slice_row['deleted'],
                    $site_id, $nbsp6, 'aa-slice-of-site',
                    sprintf(_m('Part of %s'), $site_row['name']),
                    false, $iconFor($slice_row)
                );
            }
        }

        // 2) orphan slices (no parent site) — preceded by a disabled section header.
        //    $nbsp4 prefix indents members visually under the header in the fallback
        //    path where CSS padding-left on options is ignored. Regular spaces get
        //    collapsed by some browsers; &nbsp; is preserved.
        $orphans = '';
        foreach ($slices_by_id as $slice_id => $slice_row) {
            if (empty($slice2sites[$slice_id])) {
                $orphans .= $emitOption($slice_id, $slice_row['name'], (bool)$slice_row['deleted'],
                                        '', $nbsp4, '', '', false, $iconFor($slice_row));
            }
        }
        if ($orphans !== '') {
            $options .= $emitSection(_m('Other slices')) . $orphans;
        }

        $other_type_labels = [
            'RM'     => _m("Reader Management Slice"),
            'Alerts' => _m("Alerts"),
            'J'      => _m("Jump inside control panel"),
            'Links'  => _m("Links"),
            'A'      => _m("MySQL Auth (old version)"),
            'P'      => _m("Polls"),
        ];
        foreach ($other_type_labels as $tcode => $tlabel) {
            if (empty($other_by_type[$tcode])) {
                continue;
            }
            $options .= $emitSection($tlabel);
            foreach ($other_by_type[$tcode] as $row) {
                $options .= $emitOption($row['unpackid'], $row['name'], (bool)$row['deleted'],
                                        '', $nbsp4, '', '', false, $iconFor($row));
            }
        }

        if (!AA::$module_id) {   // new slice
            $options .= '<option value="new" selected>' . safe(_m('New slice')) . '</option>';
        }

        $html  = GetLabel($profile, 'ui_manager', 'top_moduleswitchtext', '');
        $html .= '<svg id="aa-modsel-icons" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true">' . implode('', $icon_symbols) . '</svg>';
        $html .= '<select name="sel_module" aria-label="' . safe(_m('Switch module')) . '" onChange="if (this.value !== \'\') window.location=this.value">';
        $html .= $options;
        $html .= '</select>';

        $js  = "document.getElementById('aanbform').innerHTML = '" . str_replace("'", "\\'", $html) . "';";

        // Selector CSS lives in admin-ecn.css under the "Module selector" heading.

        /* Remember the chosen site in localStorage so the dropdown can highlight the right
           occurrence when the same slice appears under several sites. Purely cosmetic. */
        $js .= "(()=>{"
              . "const s=document.querySelector('select[name=sel_module]');"
              . "if(!s)return;"
              . "const K='aa_current_site_id',r=localStorage.getItem(K);"
              . "if(r&&s.selectedOptions[0]){"
              .   "const v=s.selectedOptions[0].value;"
              .   "for(const o of s.options){"
              .     "if(o.value===v&&o.dataset.siteId===r){o.selected=true;break;}"
              .   "}"
              . "}"
              . "s.addEventListener('change',()=>{"
              .   "const o=s.selectedOptions[0];"
              .   "if(o?.dataset?.siteId){localStorage.setItem(K,o.dataset.siteId);}"
              .   "else{localStorage.removeItem(K);}"
              . "});"
              /* Native prefix typeahead is sufficient — keyboard chars move the popup
                 cursor (browser-internal), and the label attribute on each <option>
                 above carries the bare slice name so matches ignore the NBSP indent.
                 Substring search would need to move the popup cursor too (JS can't
                 reach it for native <select>), so we don't override default behavior. */
              . "})();";
        $ret .= getFrmJavascriptCached($js, 'modules');
    } else {
        $ret .= GetLabel($profile, 'ui_manager', 'top_moduleselection', "&nbsp;");
    }
    return $ret;
}

// ----------------------------------------------------------------------------------------
//                                SHOW MENU

/**
 * @param  string  $activeMain     -- selected item in main (top) menu
 * @param  string  $activeSubmenu  -- selected item in sub (left) menu
 *
 * @return string
 */
function getMenu(string $activeMain, string $activeSubmenu = ''): string {
    global $slice_id;
    $ret = '';

    if ($activeMain) {

        $profile = AA_Profile::getProfile(AuthGetUid(), $slice_id); // current user settings

        // load the main AA menu (see menu.php)

        if ($menu_require = AA_Module::getModuleInfo('menu', AA::$module_id)) {

            if (!function_exists('get_aamenus')) {
                require_once __DIR__."/../" . $menu_require;
            }
            // @todo
            // Menu functions are defined in include/menu.php or modules/*/menu.php
            // We need to call last defined menu function (when switching to another
            // module). This solution is not so nice, but it removes get_aamenus()
            // redeclaration error. We probably change menus to object in the future
            $smmenus = get_aamenus();
        }

        // HACKISH: aaadmin menu needs always the _news_ lang file, even in other than slice modules
        if ($activeMain === "aaadmin") {
            mgettext_bind(Langs::currentLang(), 'news');
        }

        $nb_logo = GetLabel($profile, 'ui_manager', 'top_logo', '<a href="'. AA_INSTAL_PATH .'">'. GetAAImage('action-apps.svg', aa_version(), 112, 11). '</a>');

        // --- showMain menu -----
        // Show the Alerts and Reader management images in the header
        $title_img = '';
        switch ( $GLOBALS["g_modules"][$slice_id]["type"] ) {
            case 'Alerts':
                $title_img = a_href(AA_INSTAL_PATH . 'doc/reader.html', GetAAImage('alerts.gif', _m('Alerts'), 28, 40));
                break;
            case 'S':
                if (AA_Slice::getModuleProperty($slice_id,'type') === "ReaderManagement") {
                    $title_img = a_href(AA_INSTAL_PATH . 'doc/reader.html', GetAAImage('readers.gif', _m('Reader management'), 28, 40));
                }
                break;
        }

        // $title_title = GetLabel($profile, 'ui_manager', 'top_title', $smmenus[$activeMain]['title']);
        // $title_name  = safe($slice_id ? AA_Module::getModuleName($slice_id) : _m("New slice"));
        // $title_out   = $title_title . (($title_title && $title_name) ? ' - ' : '') . $title_name. $title_img;

        $prop_logout = $profile->getProperty('ui_manager', 'top_logout');

        $logout_out = match ($prop_logout) {
            false   => '<input type="submit" name="logout" class="aa-off" value="'._m('logout').'">',
            ''      => '',
            default => '<input type="submit" name="logout"  class="aa-off" value="'.$profile->getProperty('ui_manager', 'top_logout').'">',
        };

        $user_out = GetLabel($profile, 'ui_manager', 'top_userinfo', GetMenuLink('userinfo' === $activeMain, AuthGetUname(), IfSlPerm(PS_EDIT_SELF_USER_DATA), 'admin/um_passwd.php', '', '', 'aa-ui-userinfo'));

        // @todo change it to method=get (+ hidden fields or so) in order the Back button in browser works there better
        $search_out = IsSuperadmin() ? "<form name=aasearchform action=\"".get_admin_url('aafinder.php')."\">".StateHidden()."<input type=search name=search value=\"".safe($_REQUEST['findtext'])."\" placeholder=\"". _m('Search..') ."\"><input type=hidden name=searchinview value=1><input type=hidden name=searchinslice value=1><input type=hidden name=searchinfield value=1><input type=hidden name=searchinspot value=1><input type=hidden name=searchinobject value=1><input type=hidden name=searchincodeitems value=1><input type=hidden name=go_search value=1></form>"  : '';

        $submenu = $smmenus[$activeMain]["submenu"];
        $submenu_code = $submenu ? getSubmenu($smmenus[$submenu], $activeSubmenu) : '';
        $logout_url = get_admin_url('logout.php');

        $links = [];
        foreach ($smmenus as $aamenu =>$aamenuprop) {
            if ($aamenuprop["level"] === "main") {
                $links[$aamenu] = GetMenuLink($aamenu === $activeMain, $aamenuprop['label'], $aamenuprop['cond'] ?? true, $aamenuprop['href'], $aamenuprop['exact_href'], $aamenuprop['ico'], $aamenuprop['class'] ?? '');
            }
        }

        if ($viewlink = $links['view']) {
            unset($links['view']);
        }
        $mainmenu_code = implode(' ',$links);

        // mobile menu
        $ret .= <<<HTML
                    <div class="mobilmenu white-menu no-screen noprint">
                      <i class="ico-menu" onclick="document.querySelectorAll('.mobilmenu').forEach(el=>el.classList.toggle('active'));"></i>  
                      <nav>
                        <div class="mainmenu">
                           {$mainmenu_code}
                        </div>
                        <div class="leftmenu noprint">
                          {$submenu_code}
                        </div>
                        <form name="logoutform" method="post" action="$logout_url" class="p-3 right">
                          $user_out  {$logout_out}
                        </form>
                      </nav>
                    </div>
                HTML;

        $ret .= '
          <div class="aa-ui-top noprint" id="aa_top">
            <div class="nblogo">'.$nb_logo.'</div>
            <div class="aa-ui-nav">
              <nav class="navbar">
        ';

        $ret .= $mainmenu_code;

        $ret .= $search_out .'  
                </nav>
                <div class="aa-ui-modules">'.$viewlink.'
                  <form name="aanbform" id="aanbform" enctype="multipart/form-data" method="post" action="'. StateUrl() .'">'."\n";
        $ret .= GetModuleSelection();
        $ret .= '</form>
                <div class="aa-ui-custom-nav">
                  <form name=logoutform method="post" action="'. get_admin_url('logout.php').'" class="no-mobile">
                   '. $user_out .' '. $logout_out .'
                  </form>
                </div>
              </div>
            </div>
          </div>
          ';

        // showMain menu end -----

        $ret .= "\n<div class='aa-ui-main'>";

    // showSub menu -----
        if ($submenu) {
            $copyright = _m("Copyright (C) 2001 the <a href=\"https://www.apc.org\">Association for Progressive Communications (APC)</a>");
            $ret .= <<<HTML
                      <div class="aa-ui-leftmenu"><div class="leftmenu noprint">{$submenu_code}</div>
                        <div class='aa-ui-copyright'>{$copyright}</div>
                      </div>
                    HTML;
        }
    } else {
        $ret .= "\n<div class='aa-ui-main'>";
    }

    $ret .= "\n <div class=\"aa-ui-content\">";
    return $ret;
}

/** GetMenuLink
 * @param        $active
 * @param        $label
 * @param        $cond
 * @param        $aa_href
 * @param string $exact_href
 * @param string $ico
 * @return string
 */
function GetMenuLink($active, $label, $cond, $aa_href, $exact_href='', $ico='', $add_css=''): string
{

    $cssclass  = $active ? 'nbactive' : '';
    $cssclass .= $add_css ? ' '.$add_css : '';

    if (AA::$module_id && $cond) {
        $href = $exact_href;
        if (!$href) {
            $href = get_aa_url($aa_href);
        }
        if ($ico) {
            $label = GetAAImage($ico,$label,23,23).$label;
        }

        return a_href($href, $label, ['class'=>$cssclass]);
    }
    // maked invisible which is better, I think. Honza 2007-08-02
    // return "<span class=\"nbdisable\">$label</span>";
    return '';
}

/** showSubMenuRows function
 * @param array $aamenuitems
 * @param $active
 */
function getSubMenuRows(array $aamenuitems, $active ): string {
    global $slice_id;
    if ( !$aamenuitems ) {
       return '';
    }
    $ret = '';

    foreach ($aamenuitems as $itemshow => $item) {
        $ico = ((is_array($item) && $item['ico']) ? GetAAImage($item['ico'], $item['title'], 23,23) : '');

        if (str_starts_with($itemshow, "text")) {
            $ret .= "<li class='aa-leftmenu-text aa-menuname-$itemshow'>$item</li>\n";
        } elseif (str_starts_with($itemshow, "header")) {
            if (is_array($item)) {
                if (!isset($item["cond"]) || $item["cond"]) {
                    $ret .= "<li class='aa-leftmenu-header aa-menuname-$itemshow' title='" . $item['title'] . "'>" . $item['label'] . "</li>\n";  // $ico not shown for design reasons
                }
            } elseif ($item !== '') {
                $ret .= "<li class='aa-leftmenu-header aa-menuname-$itemshow'>$item</li>\n";
            }
        } elseif ( $item["function"] ) {
            // call some function to get menu items
            // it is better mainly for left submenus for which we need
            // database access - if we use function, it is called only if
            // submenu should be displayed.
            $function = $item["function"];
            $ret .= getSubMenuRows( $function($item["func_param"] ), $active );
        } else {
            if (!isset($item["cond"])) {
                $item["cond"] = 1;
            }
            $cssclass = (($itemshow === $active) || str_contains($item["label"], ' ▶ ')) ? 'leftmenua' : '';
            if (str_contains($item["label"], ' ▶ ')) {
                $item["label"] = str_replace(' ▶ ', ' ', $item["label"]);
            }
//            if ($itemshow == $active) {
//                $ret .= "<span class=\"leftmenua\">".$item["label"]."</span>\n";
//            } elseif (($slice_id || $item["show_always"]) && $item["cond"]) {
            if (($slice_id || $item["show_always"]) && $item["cond"]) {
                $href = ($item["exact_href"] ?: get_aa_url($item["href"],[],!$item["no_slice_id"]));
                if ($item['js']) {
                    $item['js'] = str_replace("{href}",$href,$item['js']);
                    $item['js'] = str_replace("{exact_href}",$href,$item['js']);
                    $href       = "javascript:".$item['js'];
                }
                $ret .= "<li class='aa-menuname-$itemshow'><a href=\"".$href.'" class='.$cssclass.'>'.$ico. $item["label"]."</a></li>\n";
            } elseif ( !$item["hide"] ) {
                // no need to show not active menu items 2023-02-15 HM
                // $ret .= '<li><span class="leftmenun">'.$ico.$item["label"]."</span></li>\n";
            }
        }
    }
    return $ret;
}

// ----------------------------------------------------------------------------------------
//                                SHOW SUBMENU
/** showSubmenu function
 * @param $aamenu
 * @param $active
 */
function getSubmenu($aamenu, $active,): string {
    $aamenuitems = $aamenu["items"];
    $ret = '';
    if ($submenu = getSubMenuRows( $aamenuitems, $active )) {
        $ret .= "<ul>\n  $submenu\n</ul>";
    }
    return $ret;
}

/** CreateMenuItem function
 * @param $label
 * @param $href
 * @param $cond
 * @return array
 */
function CreateMenuItem( $label, $href, $cond = true ): array
{
    return ['label' => $label, 'href' => $href, 'cond' => $cond];
}
