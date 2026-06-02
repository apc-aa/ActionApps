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
        "statistics"  => ["label"=>_m("Statistics"),      "cond"=>IsSuperadmin(),        'ico'=>'ico-statistics.png', "href"=>"admin/aa_statistics.php"],
        "history"     => ["label"=>_m("History"),         "cond"=>IfSlPerm(PS_HISTORY),  'ico'=>'ico-history.png',    "href"=>"admin/se_history.php"],
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
/**
 * Build the top-bar module/slice selector.
 *
 * Renders a <select name="sel_module"> listing the user's sites (each with its
 * child modules indented beneath it), orphan slices, and type-specific sections.
 * The markup is returned as a script that assigns it to the #aanbform element
 * via innerHTML; a fallback IIFE adds NBSP indent and reconciles the remembered
 * site for browsers without `appearance: base-select`.
 *
 * @return string  HTML/JS to embed, or empty string when no selector is shown
 */
function GetModuleSelection(): string {
    global $g_modules;
    $ret = '';

    $profile = AA_Profile::getProfile(AuthGetUid(), AA::$module_id); // current user settings

    if ( is_array($g_modules) && count($g_modules) > 1 && $profile->getProperty('ui_manager', 'top_moduleselection') === false) {
        // build the selector options:
        $permitted_slices = GetUserSlices();
        $modules_cond = ($permitted_slices==='all') ? [] : [['module.id', array_keys($permitted_slices), 'l']];

        $mymodules = DB_AA::select([],
            'SELECT LOWER(HEX(module.id)) AS unpackid, module.type, module.priority, module.deleted, module.name, slice.type AS slice_type FROM `module` LEFT JOIN `slice` ON module.id=slice.id',
            $modules_cond,
            ['module.deleted','module.priority','module.name']);

        // do not print deleted modules when you are not admin of the slice or superadmin
        if ($permitted_slices !== 'all') {
            $mymodules = array_filter($mymodules, static fn($slice) => (!$slice['deleted'] || IfSlPerm(PS_FIELDS, $slice['unpackid'])));
        }

        // Partition modules: sites (W), regular slices (S, non-RM), and other types
        // (RM, Polls, Alerts, Links, Jump, ...). Order inside each bucket follows
        // the query order (deleted asc, priority asc, name asc).
        // $non_sites_by_id mirrors the union of $slices_by_id + flattened $other_by_type
        // so the relation query and the under-site bucketing can address any non-site
        // module by id, regardless of its type-specific bucket.
        $sites           = [];   // unpackid => row
        $slices_by_id    = [];   // unpackid => row  (non-RM slices)
        $other_by_type   = [];   // type => [row, ...]  (RM bucket uses key 'RM')
        $non_sites_by_id = [];   // unpackid => row  (everything that isn't 'W')
        foreach ($mymodules as $row) {
            if ($row['type'] === 'W') {
                $sites[$row['unpackid']] = $row;
                continue;
            }
            $non_sites_by_id[$row['unpackid']] = $row;
            if ($row['type'] === 'S' && $row['slice_type'] !== 'ReaderManagement') {
                $slices_by_id[$row['unpackid']] = $row;
            } else {
                $bucket = ($row['type'] === 'S') ? 'RM' : $row['type'];
                $other_by_type[$bucket][] = $row;
            }
        }

        // One batched query for site->module memberships, scoped to all visible sites
        // and all non-site modules — including RM/Polls/Alerts/Links/Jump, since the
        // site editor (modules/site/modedit.php) lets the user attach any module type
        // via REL_FLAG_MODULE_DEPEND. Wrapped in Contentcache for cross-request reuse
        // (mirrors AA_Module_Site::getRelatedModules()). We index by slice (a module can
        // hang under several sites), which serves both the under-site bucketing and the
        // "is this module unassigned?" checks below.
        $slice2sites = [];
        if (!empty($sites) && !empty($non_sites_by_id)) {
            $rels = AA::Contentcache()->get_result(['AA\IO\DB\DB_AA','select'], [[],
                'SELECT LOWER(HEX(source_id)) AS site_id, LOWER(HEX(destination_id)) AS slice_id FROM `relation`',
                [['source_id',      array_keys($sites),           'l'],
                 ['destination_id', array_keys($non_sites_by_id), 'l'],
                 ['flag',           REL_FLAG_MODULE_DEPEND,        'i']]]);
            foreach ($rels as $rel) {
                $slice2sites[$rel['slice_id']][$rel['site_id']] = true;
            }
        }

        // Pre-bucket modules under each site. Iterate $mymodules (not $non_sites_by_id)
        // to inherit the query order (deleted asc, priority asc, name asc) across the
        // combined pool, then refine each site's children with a module-type tier
        // (see the uasort below): regular slices first, then RM/Alerts/Jump/Links/Auth/Polls
        // — the same order as the unassigned sections ($other_type_labels) further down.
        $type_rank = ['S' => 0, 'RM' => 1, 'Alerts' => 2, 'J' => 3, 'Links' => 4, 'A' => 5, 'P' => 6];
        $rankOf = static function (array $row) use ($type_rank): int {
            $bucket = ($row['type'] === 'S')
                ? ((($row['slice_type'] ?? '') === 'ReaderManagement') ? 'RM' : 'S')
                : $row['type'];
            return $type_rank[$bucket] ?? 99;
        };
        $slices_under_site = [];
        foreach ($mymodules as $row) {
            if ($row['type'] === 'W') {
                continue;
            }
            $mod_id = $row['unpackid'];
            foreach (array_keys($slice2sites[$mod_id] ?? []) as $site_id) {
                $slices_under_site[$site_id][$mod_id] = $row;
            }
        }
        // Order each site's children: (un)deleted, then priority, then the module-type
        // tier, then name. uasort is stable (PHP 8.0+), so rows tied on the first three
        // keys keep the query's name order; it preserves the $mod_id keys used below.
        foreach ($slices_under_site as $site_id => $kids) {
            uasort($kids, static fn(array $a, array $b): int =>
                [(int)$a['deleted'], (int)$a['priority'], $rankOf($a)]
                <=> [(int)$b['deleted'], (int)$b['priority'], $rankOf($b)]);
            $slices_under_site[$site_id] = $kids;
        }

        /* Single indent depth for every sub-row (site-child slices, orphan slices,
           and non-slice modules under a header), expressed as a leading-space count.
           Modern browsers (`appearance: base-select`) indent via CSS padding keyed
           on the option class, so the default markup carries no layout spacing and
           stays clean for native typeahead. Browsers without base-select get the
           indent from the fallback JS, which injects this many NBSPs into the text. */
        $indent = 4;

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
         *  - $indent is the hierarchy depth (leading-space count). Modern browsers
         *    indent via CSS padding keyed on $class, so the markup stays free of
         *    layout spacing; the fallback JS injects $indent NBSPs into the option
         *    text for browsers that lack `appearance: base-select`.
         *  - $class adds a CSS hook for modern browsers (`appearance: base-select`).
         *  - $aria_desc fills `aria-description` to convey parent-site context to AT
         *    in lieu of the dropped <optgroup> grouping announcement.
         *  - $disabled emits a non-selectable header-style option (used when the user
         *    can see a site's child slices but lacks permission to the site itself).
         *  - $icon adds an `aa-ic-{icon}` class on the option. CSS in admin-ecn.css
         *    paints the matching SVG via an `::after` background-image — pseudo-elements
         *    render reliably inside <option> in Chromium's base-select popup and keep
         *    the option markup free of presentational child nodes.
         */
        // Switching to a slice of the *same module type* keeps the user on the page they are
        // on: a bare "?module_id=$id" href resolves against the current document URL, so e.g.
        // se_fields.php stays se_fields.php for the new slice (the long-standing convenience).
        // The exception is the per-item pages (item editor / info / history / statistics): they
        // need an item id on top of module_id, so staying would land on a foreign, item-less
        // page (the "strange page" bug). For those - and for a switch to a different module type
        // - we open the target module's manager (index) instead.
        $cur_url      = AA::$module_id ? AA_Module::getModuleInfo('url', AA::$module_id) : '';
        // SCRIPT_NAME only feeds the closed in_array() whitelist below - a spoofed value just
        // falls through to the (safe) full-index branch, so no sanitisation beyond basename is needed.
        $cur_script   = basename(parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '');
        $on_item_page = in_array($cur_script, ['itemedit.php', 'iteminfo.php', 'itemhistory.php', 'itemstats.php'], true);

        // Track whether the HTML 'selected' attribute has already been emitted: a slice
        // that lives under multiple sites would otherwise get 'selected' on every row,
        // causing a wrong-row flash before the localStorage JS reconciles.
        $selected_emitted = false;
        $emitOption = static function (string $id, string $name, bool $deleted,
                                       string $site_id = '', int $indent = 0,
                                       string $class = '', string $aria_desc = '',
                                       bool $disabled = false, string $icon = '') use (&$selected_emitted, $cur_url, $on_item_page): string {
            $mod_dir = AA_Module::getModuleInfo('url', $id);
            // Same module type as the current page (and a page that lives on module_id alone):
            // keep the user where they are. Otherwise open the target module's manager.
            $val = ($cur_url !== '' && $mod_dir === $cur_url && !$on_item_page)
                 ? "?module_id=$id"
                 : AA_INSTAL_PATH.$mod_dir . "?module_id=$id";
            $label   = safe($name);
            if ($deleted) {
                $label = "&times;&times;&times; $label &times;&times;&times;";
            }
            if ($icon !== '') {
                // Icon is painted by CSS ::after on the option (see admin-ecn.css).
                // We just add a marker class; the data: URI lives in CSS so it can
                // share the popup's accent colour with the rest of the skin.
                $class = trim($class . ' aa-ic-' . $icon);
            }

            // No NBSP indent or label= in the default markup: the option text is the
            // bare (optionally deleted-decorated) name, so native typeahead matches
            // what the user reads. Indentation is CSS padding in modern browsers and
            // JS-injected NBSPs in the fallback (see the data-indent handling below).
            $opt = '<option value="' . safe($val) . '"';
            if ($class !== '') {
                $opt .= ' class="' . safe($class) . '"';
            }
            if ($indent > 0) {
                $opt .= ' data-indent="' . $indent . '"';
            }
            if ($site_id !== '') {
                $opt .= ' data-site-id="' . safe($site_id) . '"';
            }
            if ($aria_desc !== '') {
                $opt .= ' aria-description="' . safe($aria_desc) . '"';
            }
            if ($disabled) {
                $opt .= ' disabled';
            } elseif (!$selected_emitted && AA::$module_id === $id) {
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
                $site_id, 0,
                $site_perm ? 'aa-site' : 'aa-site aa-site-readonly',
                '', !$site_perm, 'site'
            );
            foreach ($kids as $slice_id => $slice_row) {
                $options .= $emitOption(
                    $slice_id, $slice_row['name'], (bool)$slice_row['deleted'],
                    $site_id, $indent, 'aa-slice-of-site',
                    sprintf(_m('Part of %s'), $site_row['name']),
                    false, $iconFor($slice_row)
                );
            }
        }

        // 2) orphan slices (no parent site) — preceded by a disabled section header.
        //    $indent gives the fallback indent depth (NBSPs injected by JS) for
        //    browsers where CSS padding-left on options is ignored.
        $orphans = '';
        foreach ($slices_by_id as $slice_id => $slice_row) {
            if (empty($slice2sites[$slice_id])) {
                $orphans .= $emitOption($slice_id, $slice_row['name'], (bool)$slice_row['deleted'],
                                        '', $indent, '', '', false, $iconFor($slice_row));
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
        // Only modules without a parent-site relation land in the type-specific
        // sections; assigned ones were already emitted under their site above.
        foreach ($other_type_labels as $tcode => $tlabel) {
            if (empty($other_by_type[$tcode])) {
                continue;
            }
            $unassigned = array_filter($other_by_type[$tcode],
                static fn(array $row): bool => empty($slice2sites[$row['unpackid']]));
            if (empty($unassigned)) {
                continue;
            }
            $options .= $emitSection($tlabel);
            foreach ($unassigned as $row) {
                $options .= $emitOption($row['unpackid'], $row['name'], (bool)$row['deleted'],
                                        '', $indent, '', '', false, $iconFor($row));
            }
        }

        if (!AA::$module_id) {   // new slice
            $options .= '<option value="new" selected>' . safe(_m('New slice')) . '</option>';
        }

        $html  = GetLabel($profile, 'ui_manager', 'top_moduleswitchtext', '');
        $html .= '<select name="sel_module" aria-label="' . safe(_m('Switch module')) . '" onChange="if (this.value !== \'\') window.location=this.value">';
        $html .= $options;
        $html .= '</select>';

        // Embed $html as a single-quoted JS string literal. Escape backslashes
        // first, then single quotes — a module name containing '\' (or a trailing
        // one) would otherwise corrupt the literal and break the whole selector.
        $js  = "document.getElementById('aanbform').innerHTML = '" . str_replace(['\\', "'"], ['\\\\', "\\'"], $html) . "';";

        // Selector CSS lives in admin-ecn.css under the "Module selector" heading.

        /* Remember the chosen site in localStorage so the dropdown can highlight the right
           occurrence when the same slice appears under several sites. Purely cosmetic. */
        $js .= "(()=>{"
              . "const s=document.querySelector('select[name=sel_module]');"
              . "if(!s)return;"
              /* Browsers without `appearance: base-select` (Firefox, Safari) render a
                 native listbox that ignores option padding, so the CSS indent never
                 shows. Inject leading NBSPs (U+00A0) from data-indent to restore the
                 hierarchy there. Their native typeahead still cycles, so they need no
                 keyboard help. Chromium's base-select popup gets clean text (CSS-padding
                 indent) plus a JS typeahead handler below — its built-in type-to-search
                 only ever jumps to the *first* match and never cycles, which the handler
                 replaces with begins-with-then-substring matching that also cycles. */
              . "if(!CSS.supports('appearance','base-select')){"
              .   "for(const o of s.options){"
              .     "const n=+o.dataset.indent||0;"
              .     "if(n)o.textContent='\\u00A0'.repeat(n)+o.textContent;"
              .   "}"
              . "}else{"
                  /* base-select options are real focusable DOM nodes, so focus() moves
                     the popup cursor (legacy <select> can't). We intercept printable keys
                     while the popup is open, suppress the native first-match search, and
                     focus our own match; Enter then commits the focused option as usual.
                     Match rule: a single repeated character cycles through every option
                     starting with it; a multi-char buffer (fast typing, 700ms window)
                     matches begins-with first and falls back to a substring search.
                     Leading whitespace in option text is ignored (trim). */
              .   "let buf='',t=null;"
              .   "const nm=o=>o.textContent.trim().toLowerCase();"
              .   "s.addEventListener('keydown',e=>{"
              .     "if(!s.matches(':open'))return;"
              .     "if(e.key.length!==1||e.ctrlKey||e.metaKey||e.altKey)return;"
              .     "e.preventDefault();"
              .     "clearTimeout(t);t=setTimeout(()=>{buf='';},700);"
              .     "buf+=e.key.toLowerCase();"
              .     "const same=[...buf].every(c=>c===buf[0]),q=same?buf[0]:buf;"
              .     "const opts=[...s.options].filter(o=>!o.disabled);"
              .     "let pool=opts.filter(o=>nm(o).startsWith(q));"
              .     "if(!pool.length)pool=opts.filter(o=>nm(o).includes(q));"
              .     "if(!pool.length)return;"
              .     "const cur=document.activeElement;"
              .     "let nx;"
              .     "if(same){const i=pool.indexOf(cur);nx=pool[(i+1)%pool.length];}"
              .     "else{nx=pool.includes(cur)?cur:pool[0];}"
              .     "nx.focus();"
              .   "});"
              . "}"
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
 * @param  string  $activeMain       -- selected item in main (top) menu
 * @param  string  $activeSubmenu    -- selected item in sub (left) menu
 * @param  string  $submenuOverride  -- render this named submenu (from menu.php) instead of the
 *                                       one the main menu defaults to. Lets a page keep its top
 *                                       highlight (e.g. 'itemmanager') while showing a different
 *                                       left rail (e.g. the dynamic 'itemcontext_submenu').
 *
 * @return string
 */
function getMenu(string $activeMain, string $activeSubmenu = '', string $submenuOverride = ''): string {
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

        $submenu      = $submenuOverride !== '' ? $submenuOverride : ($smmenus[$activeMain]["submenu"] ?? '');
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

            // The item-context rail (Edit item / Item info / History / Statistics) can be
            // collapsed to an icons-only strip — every row there carries an icon. Other
            // submenus don't, so the toggle + collapse CSS are gated to this one submenu key.
            $collapsible    = ($submenu === 'itemcontext_submenu');
            $rail_class     = $collapsible ? 'aa-ui-leftmenu aa-leftmenu-collapsible' : 'aa-ui-leftmenu';
            $rail_prescript = '';
            $rail_toggle    = '';
            if ($collapsible) {
                $toggle_label = safe(_m('Collapse / expand menu'));
                // Apply the saved state on <html> BEFORE the rail paints, so a collapsed
                // rail never flashes at full width (mirrors the aa_current_site_id idiom above).
                // NB: this HTML is later run through AA::Un() (unalias). The Stringexpander
                // dispatches a "{cmd...}" only when the brace content STARTS with an AA command
                // token immediately followed by ':' or '(' (stringexpand.php ~6592: strspn picks
                // the leading [a-z0-9_] token). So "{if(...)}" is read as {if(...} and eaten; a
                // single leading space ("{ if(...}") already defeats it (strspn → 0, no command).
                // Here we sidestep the issue entirely: classList.toggle(name, force) has no such
                // leading token, so unalias leaves the script intact.
                $rail_prescript = "<script>try{document.documentElement.classList.toggle('aa-itemrail-collapsed',"
                    ."localStorage.getItem('aa_itemrail_collapsed')==='1')}catch(e){}</script>";
                $rail_toggle = "<button type=\"button\" class=\"aa-rail-toggle\" id=\"aa-rail-toggle\""
                    ." title=\"$toggle_label\" aria-label=\"$toggle_label\" aria-expanded=\"true\">"
                    ."<span class=\"aa-rail-toggle-ico\" aria-hidden=\"true\"></span></button>"
                    ."<script>(()=>{const h=document.documentElement,b=document.getElementById('aa-rail-toggle');"
                    ."if(!b)return;const s=()=>b.setAttribute('aria-expanded',h.classList.contains('aa-itemrail-collapsed')?'false':'true');"
                    ."s();b.addEventListener('click',()=>{const c=h.classList.toggle('aa-itemrail-collapsed');"
                    ."try{localStorage.setItem('aa_itemrail_collapsed',c?'1':'0');}catch(e){}s();});})();</script>";
            }

            $ret .= $rail_prescript . <<<HTML
                      <div class="{$rail_class}"><div class="leftmenu noprint">{$rail_toggle}{$submenu_code}</div>
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
        $ico = ((is_array($item) && ($item['ico'] ?? '')) ? GetAAImage($item['ico'], $item['title'] ?? '', 23,23) : '');

        if (str_starts_with($itemshow, "text")) {
            $ret .= "<li class='aa-leftmenu-text aa-menuname-$itemshow'>$item</li>\n";
        } elseif (str_starts_with($itemshow, "header")) {
            if (is_array($item)) {
                if (!isset($item["cond"]) || $item["cond"]) {
                    $ret .= "<li class='aa-leftmenu-header aa-menuname-$itemshow' title='" . safe($item['title'] ?? '') . "'>" . $item['label'] . "</li>\n";  // $ico not shown for design reasons
                }
            } elseif ($item !== '') {
                $ret .= "<li class='aa-leftmenu-header aa-menuname-$itemshow'>$item</li>\n";
            }
        } elseif ( $item["function"] ?? null ) {
            // call some function to get menu items
            // it is better mainly for left submenus for which we need
            // database access - if we use function, it is called only if
            // submenu should be displayed.
            $function = $item["function"];
            $ret .= getSubMenuRows( $function($item["func_param"] ?? null), $active );
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
            if (($slice_id || ($item["show_always"] ?? false)) && $item["cond"]) {
                $href = (($item["exact_href"] ?? '') ?: get_aa_url($item["href"] ?? '', [], !($item["no_slice_id"] ?? false)));
                $is_js = false;
                if ($item['js'] ?? '') {
                    $item['js'] = str_replace("{href}",$href,$item['js']);
                    $item['js'] = str_replace("{exact_href}",$href,$item['js']);
                    $href       = "javascript:".$item['js'];
                    $is_js      = true;
                }
                $class_attr = $cssclass ? " class=\"$cssclass\"" : '';
                // title surfaces the label as a tooltip when the rail is collapsed to icons only.
                $title_attr = (($item['title'] ?? '') !== '') ? ' title="'.safe($item['title']).'"' : '';
                // safe() the URL href (it can carry a user-derived id, e.g. the item-context rail);
                // leave a javascript: href raw so escaping & inside inline JS does not break it.
                $safe_href = $is_js ? $href : safe($href);
                $ret .= "<li class='aa-menuname-$itemshow'><a href=\"$safe_href\"$class_attr$title_attr>".$ico. $item["label"]."</a></li>\n";
            } elseif ( !($item["hide"] ?? false) ) {
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
