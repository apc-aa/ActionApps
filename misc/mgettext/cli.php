<?php

declare(strict_types=1);

die(); // uncomment in case you want to run it


/**
 * Mini-gettext maintenance CLI — extracts _m() messages from sources and
 * (re)generates the include/lang/ translation files.
 *
 * Replaces the old web UI (index.php + xmgettext.php, 2003). Key differences:
 *  - writes directly to include/lang/ (no copy-to-destination-dir dance)
 *  - scans src/ and all other code; verifies every file using _m() is covered
 *  - UTF-8 files are the translation masters; one-byte-charset variants
 *    (cz, sk, …) are derived from them by charset conversion
 *  - generated files use a single `return [...]` literal (OPcache-friendly)
 *
 * Usage:
 *   php misc/mgettext/cli.php [options]
 *     --check               only run scan-coverage check + extraction warnings, write nothing
 *     --groups=news,output  process only listed groups (default: all)
 *     --langs=cz-utf8,cz    process only listed languages (default: all from Langs registry)
 *     --rename-map=FILE     PHP file returning [old_message => new_message] map; translations
 *                           of renamed messages are carried over (used when fixing EN strings)
 *     --lang-dir=DIR        write generated files to DIR instead of include/lang
 *                           (existing translations are still read from include/lang; for testing)
 *     --seed-dir=DIR        when seeding a new utf8 master from a one-byte file, prefer the
 *                           copy in DIR over include/lang; for existing masters, fill empty
 *                           translations from the DIR copy (used for pre-corruption rescue
 *                           copies recovered from SVN history — see r4602)
 *
 * @package MiniGetText
 */

use AA\Lang\LangFile;
use AA\Lang\MsgExtractor;
use AA\Util\Langs;

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$AA_BASE = dirname(__DIR__, 2);
require_once $AA_BASE . '/vendor/autoload.php';

if (!defined('DEFAULT_LANG_INCLUDE')) {
    define('DEFAULT_LANG_INCLUDE', 'en-utf8_news_lang.php');  // Langs::getLang() fallback; irrelevant for generation
}

/* ----------------------------------------------------------------------------
 * Scan configuration
 *
 * Groups of files assigned to the lang-file sections (??_<group>_lang.php).
 * Sections exist to keep the loaded array small — especially `output`, which is
 * loaded on every public page.
 *
 * Entry syntax:  'dir/' recursive directory, './' top-level files only,
 *                'file.php' single file, '-path' exclude (file or 'dir/').
 */
$LANG_GROUPS = [
    // _alerts_lang.php — Alerts module
    'alerts' => ['modules/alerts/'],
    // _news_lang.php — Control panel (loaded on every admin page) + everything not output-specific
    'news' => [
        './',
        'admin/',
        'central/',
        'include/',
        'src/',
        'modules/jump/',
        'misc/datovka/',
        'misc/msconvert/',
        '-./slice.php',
        '-admin/param_wizard.php',
        '-include/lang/',
        '-include/htmlMimeMail/',
        '-include/ConvertTables/',
        '-include/custom/',
        '-include/view.php',
        '-include/constants_param_wizard.php',
        '-include/mgettext.php',    // its only _m() call is the _mdelayed() wrapper
    ],
    // _site_lang.php — Site module (public)
    'site' => [
        'modules/site/',
        '-modules/site/site.php',   // the dispatcher binds `output`, see below
    ],
    // _links_lang.php — Links module
    'links' => [
        'modules/links/',
        'include/searchbar.class.php',
        'include/util.php',
    ],
    // _polls_lang.php — Polls module
    'polls' => ['modules/polls/'],
    // _output_lang.php — public output (slice.php / view.php pages)
    'output' => [
        './slice.php',
        './view.php',
        './discussion.php',
        'include/discussion.php',
        'include/util.php',
        'include/slice.php',
        'include/view.php',
        'include/item.php',
        'include/scroller.php',
        'include/itemview.php',
        'misc/proposefieldchange.php',
        'modules/site/site.php',    // site dispatcher - the only domain it binds (site.php:305)
    ],
    // _param_wizard_lang.php — parameter wizard only
    'param_wizard' => [
        'admin/param_wizard.php',
        'include/constants_param_wizard.php',
    ],
];

/** Directories never scanned and exempt from the coverage check */
$GLOBAL_EXCLUDES = [
    'vendor/', 'tests/', 'doc/', 'embed/', 'css/', 'javascript/', 'images/',
    'include/lang/', 'include/htmlMimeMail/', 'include/ConvertTables/', 'include/custom/',
    'include/mgettext.php',         // its only _m() call is the _mdelayed() wrapper
    'misc/mgettext/', 'misc/htmleditor/',
    'modules/module_TEMPLATE/',
];

/**
 * Messages translated at runtime via _mdelayed($variable) — static extraction cannot
 * see them, so their translations would be misclassified as unused and commented out.
 * Listed per group; their existing translations are kept active in the generated files.
 * When you add an _mdelayed() call, add its value domain here.
 */
$RUNTIME_MESSAGES = [
    'news' => array_merge(
        ['AUTHOR', 'EDITOR', 'ADMINISTRATOR', 'SUPER'],               // AA_Perm::ROLES keys — include/um_util.php GetModulePermRow()
        ['User', 'Group'],                                            // perm-system user types — include/se_users.php UserTypeLabel()
        ['Add', 'Add&nbsp;Mutual', 'Backward', 'Good', 'OK', 'Bad'],  // formutil.php TPS[*][*]['str'] — admin/related_sel.php
        // metabase column names — include/metabase.class.php getAsProperty()
        array_keys(array_merge(...array_filter(array_column(require $AA_BASE . '/include/metabase_schema.php', 'c'), 'is_array'))),
    ),
];

/* ------------------------------------------------------------------------- */

$opts = getopt('', ['check', 'groups:', 'langs:', 'rename-map:', 'lang-dir:', 'seed-dir:']);
$only_groups = isset($opts['groups']) ? explode(',', $opts['groups']) : null;
$only_langs = isset($opts['langs']) ? explode(',', $opts['langs']) : null;
$check_only = isset($opts['check']);
$rename_map = [];
if (isset($opts['rename-map'])) {
    $rename_map = require $opts['rename-map'];
    if (!is_array($rename_map)) {
        fwrite(STDERR, "rename-map file must return an array\n");
        exit(2);
    }
}

$extractor = new MsgExtractor();
$langfile = new LangFile();
$lang_dir = $AA_BASE . '/include/lang';                 // existing translations are read from here
$out_dir = rtrim($opts['lang-dir'] ?? $lang_dir, '/'); // generated files are written here
if (!is_dir($out_dir)) {
    fwrite(STDERR, "ERROR: --lang-dir '$out_dir' is not a directory\n");
    exit(2);
}
$seed_dir = rtrim($opts['seed-dir'] ?? '', '/');
if ($seed_dir !== '' && !is_dir($seed_dir)) {
    fwrite(STDERR, "ERROR: --seed-dir '$seed_dir' is not a directory\n");
    exit(2);
}

/** whether a language code should be processed given the --langs filter ($only is null = all) */
function lang_wanted(?array $only, string $code): bool
{
    return $only === null || in_array($code, $only, true);
}

/** carries translations over message renames (EN string fixes): old key's translation
 *  moves to the new key unless the new key is already translated;
 *  AI-translation marks in $marks follow their translation (or vanish with it) */
function apply_renames(array $translations, array $rename_map, array &$marks = []): array
{
    foreach ($rename_map as $from => $to) {
        if (isset($translations[$from])) {
            if ($translations[$from] !== '' && ($translations[$to] ?? '') === '') {
                $translations[$to] = $translations[$from];
                if (isset($marks[$from])) {
                    $marks[$to] = $marks[$from];
                }
            }
            unset($translations[$from]);   // the old key never returns — keeping it would only clutter Unused
        }
        unset($marks[$from]);
    }
    return $translations;
}

/** expands group entry list to a sorted list of repo-relative php files */
function expand_group(string $base, array $entries): array
{
    $files = [];
    $excludes = [];
    foreach ($entries as $entry) {
        if ($entry[0] === '-') {
            $excludes[] = substr($entry, 1);
            continue;
        }
        if ($entry === './') {
            foreach (glob("$base/*.php") ?: [] as $f) {
                $files[substr($f, strlen($base) + 1)] = 1;
            }
        } elseif (str_ends_with($entry, '/')) {
            if (!is_dir("$base/$entry")) {
                fwrite(STDERR, "ERROR: \$LANG_GROUPS entry '$entry' is not a directory\n");
                exit(2);
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator("$base/$entry", FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[substr($f->getPathname(), strlen($base) + 1)] = 1;
                }
            }
        } else {
            $files[$entry] = 1;
        }
    }
    foreach (array_keys($files) as $file) {
        foreach ($excludes as $ex) {
            if ($file === $ex || (str_ends_with($ex, '/') && str_starts_with($file, $ex))) {
                unset($files[$file]);
            }
        }
    }
    $files = array_keys($files);
    sort($files);
    return $files;
}

/* --- 1. scan-coverage check: every file calling _m() must be in some group --- */

$covered = [];
$group_files = [];
foreach ($LANG_GROUPS as $group => $entries) {
    $group_files[$group] = expand_group($AA_BASE, $entries);
    foreach ($group_files[$group] as $f) {
        $covered[$f] = 1;
    }
}

$uncovered = [];
$repo_it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($AA_BASE, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);
foreach ($repo_it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $rel = substr($f->getPathname(), strlen($AA_BASE) + 1);
    if (isset($covered[$rel]) || str_contains($rel, '/.svn/')) {
        continue;
    }
    foreach ($GLOBAL_EXCLUDES as $ex) {
        if (str_starts_with($rel, $ex)) {
            continue 2;
        }
    }
    $code = file_get_contents($f->getPathname());
    if ($code !== false && $extractor->sourceUsesM($code)) {
        $uncovered[] = $rel;
    }
}

if ($uncovered) {
    fwrite(STDERR, "ERROR: files calling _m() but not covered by any lang group:\n  "
        . implode("\n  ", $uncovered)
        . "\nAdd them to \$LANG_GROUPS (or \$GLOBAL_EXCLUDES) in " . __FILE__ . "\n");
    exit(1);
}
echo 'Coverage OK: every file calling _m() belongs to a lang group ('
    . count($covered) . " files scanned)\n";

/* --- 2. resolve languages: masters vs derived one-byte variants --- */

$all_codes = array_keys(Langs::getNames());
$masters = [];   // master code => list of derived codes
foreach ($all_codes as $code) {
    if (str_ends_with($code, '-utf8') || !in_array("$code-utf8", $all_codes, true)) {
        $masters[$code] = [];
    }
}
foreach ($all_codes as $code) {
    if (isset($masters["$code-utf8"])) {
        $masters["$code-utf8"][] = $code;
    }
}

/* --- 3. extract all groups (unused/carry checks need the cross-group picture) --- */

$total_warnings = [];
$group_messages = [];   // group => message => file => rows
foreach (array_keys($LANG_GROUPS) as $group) {
    $result = $extractor->extractFromFiles($AA_BASE, $group_files[$group]);
    $group_messages[$group] = $result['messages'];
    foreach ($result['warnings'] as $w) {
        $total_warnings[] = "[$group] $w";
    }
    printf("%-13s %4d messages from %3d files\n", $group, count($group_messages[$group]), count($group_files[$group]));
}

/* --- 4. preload current translations of every group — BEFORE any file is rewritten,
 *        because translations may move between groups (e.g. src/Manager.php links -> news) --- */

$old_maps = [];    // master => group => message => translation
$old_marks = [];   // master => group => message => AI-translation marker text ('ai 2026-06')
if (!$check_only) {
    foreach (array_keys($masters) as $master) {
        foreach (array_keys($LANG_GROUPS) as $g) {
            $marks = LangFile::readMarks("$lang_dir/{$master}_{$g}_lang.php");
            $old_maps[$master][$g] = apply_renames($langfile->read("$lang_dir/{$master}_{$g}_lang.php"), $rename_map, $marks);
            $old_marks[$master][$g] = $marks;
        }
    }
}

/* --- 5. regenerate per group x language --- */

foreach (array_keys($LANG_GROUPS) as $group) {
    if ($check_only || ($only_groups && !in_array($group, $only_groups, true))) {
        continue;
    }
    $messages = $group_messages[$group];   // message => file => rows
    $runtime_keys = array_fill_keys($RUNTIME_MESSAGES[$group] ?? [], true);

    foreach ($masters as $master => $derived_codes) {
        // skip a whole master block only when neither it nor any of its derived variants is wanted
        $block_codes = [$master, ...$derived_codes];
        if (!array_filter($block_codes, fn($c) => lang_wanted($only_langs, $c))) {
            continue;
        }
        $old = $old_maps[$master][$group];

        // new utf8 master with no file yet — seed its translations from an existing
        // one-byte variant (e.g. fr-utf8 from the legacy iso-8859-1 fr file);
        // a pre-corruption rescue copy in --seed-dir takes precedence
        if (!$old) {
            foreach ($derived_codes as $code) {
                $src = $code;
                $old_1b = $seed_dir !== '' ? $langfile->read("$seed_dir/{$code}_{$group}_lang.php") : [];
                if ($old_1b) {
                    $src = "$code (seed-dir)";
                } else {
                    $old_1b = $langfile->read("$lang_dir/{$code}_{$group}_lang.php");
                }
                if ($old_1b) {
                    $old = apply_renames(LangFile::toUtf8($old_1b, Langs::getCharset($code)), $rename_map);
                    // legacy one-byte files wrote the English source as "not translated yet"
                    $old = array_filter($old, fn($t, $m) => $t !== (string) $m, ARRAY_FILTER_USE_BOTH);
                    echo "  seeding {$master}_{$group} from $src (" . count($old) . " messages)\n";
                    break;
                }
            }
        }

        // gap-fill an existing master: empty/missing translations are taken from the
        // seed-dir copy of a one-byte variant (master values always win over seed)
        if ($old && $seed_dir !== '') {
            foreach ($derived_codes as $code) {
                $seed_1b = $langfile->read("$seed_dir/{$code}_{$group}_lang.php");
                if (!$seed_1b) {
                    continue;
                }
                $seed = apply_renames(LangFile::toUtf8($seed_1b, Langs::getCharset($code)), $rename_map);
                $filled = 0;
                foreach ($seed as $message => $translation) {
                    if ($translation !== '' && $translation !== $message && ($old[$message] ?? '') === '') {
                        $old[$message] = $translation;
                        $filled++;
                    }
                }
                if ($filled) {
                    echo "  gap-filled {$master}_{$group}: $filled translations from $code (seed-dir)\n";
                }
                break;
            }
        }

        $entries_out = [];
        $carried = [];
        $marks_out = [];   // message => AI-translation marker — follows the translation it belongs to
        // carry this group's own stored marker for a message that keeps its translation as-is
        $keep_mark = function (string $message) use (&$marks_out, $old_marks, $master, $group): void {
            if (isset($old_marks[$master][$group][$message])) {
                $marks_out[$message] = $old_marks[$master][$group][$message];
            }
        };
        foreach ($messages as $message => $locations) {
            $comments = [];
            foreach ($locations as $file => $rows) {
                $comments[] = "$file, row " . implode(', ', array_unique($rows));
            }
            // a translation equal to the English source is a decision to keep English here
            // (another section may translate the same text) — kept, never refilled by the carry
            $translation = $old[$message] ?? '';
            $mark = $old_marks[$master][$group][$message] ?? null;
            if ($translation === '') {
                $mark = null;
                // the message may already be translated in another section file
                // (it moved here, or appears in several sections) — reuse it
                foreach ($old_maps[$master] ?? [] as $g => $map) {
                    if ($g !== $group && ($map[$message] ?? '') !== '' && $map[$message] !== $message) {
                        $translation = $map[$message];
                        $mark = $old_marks[$master][$g][$message] ?? null;
                        $carried[$g] = ($carried[$g] ?? 0) + 1;
                        break;
                    }
                }
            }
            if ($mark !== null) {
                $marks_out[$message] = $mark;
            }
            $entries_out[] = [
                'message' => $message,
                'translation' => $translation,
                'comments' => $comments,
            ];
        }
        foreach ($carried as $g => $n) {
            echo "  {$master}_{$group}: carried $n translations from $g\n";
        }

        // translated messages no longer extracted in this group:
        //  - _mdelayed() value domains stay active (runtime block)
        //  - messages extracted by another group are dropped here when that group has
        //    the same translation or none (then it carries this one over) — they live there;
        //    a *differing* per-section variant is kept in the commented block for reference
        //  - the rest is written commented-out (revived if the message returns)
        $unused = [];
        $runtime_out = [];
        $elsewhere = 0;
        foreach ($old as $message => $translation) {
            if ($translation === '' || $translation === $message || isset($messages[$message])) {
                continue;
            }
            if (isset($runtime_keys[$message])) {
                $runtime_out[$message] = $translation;
                $keep_mark($message);
                continue;
            }
            foreach ($group_messages as $g => $msgs) {
                if ($g !== $group && isset($msgs[$message])) {
                    $other = $old_maps[$master][$g][$message] ?? '';
                    if ($other === '' || $other === $message || $other === $translation) {
                        $elsewhere++;
                        continue 2;
                    }
                    break;   // differing variant — falls through to the commented block
                }
            }
            $unused[$message] = $translation;
            $keep_mark($message);
        }
        if ($elsewhere) {
            echo "  {$master}_{$group}: dropped $elsewhere translations now living in another group\n";
        }

        if (lang_wanted($only_langs, $master)) {
            $langfile->write("$out_dir/{$master}_{$group}_lang.php", $master, $entries_out, $unused, '', $runtime_out, $marks_out);
        }

        // derived one-byte variants: same entries, values converted from the master
        foreach ($derived_codes as $code) {
            if (!lang_wanted($only_langs, $code)) {
                continue;
            }
            $charset = Langs::getCharset($code);
            $lossy = [];
            $translations = array_column($entries_out, 'translation', 'message');
            $converted = LangFile::convertValues($translations, $charset, $lossy);
            $entries_1b = [];
            foreach ($entries_out as $e) {
                $entries_1b[] = ['message' => $e['message'], 'translation' => $converted[$e['message']], 'comments' => $e['comments']];
            }
            $unused_1b = LangFile::convertValues($unused, $charset, $lossy);
            $runtime_1b = LangFile::convertValues($runtime_out, $charset, $lossy);
            foreach ($lossy as $message => $value) {
                $total_warnings[] = "[$group/$code] lossy $charset conversion: " . mb_substr($message, 0, 60);
            }
            $langfile->write("$out_dir/{$code}_{$group}_lang.php", $code, $entries_1b, $unused_1b, $master, $runtime_1b, $marks_out);
        }
    }
}

if ($total_warnings) {
    echo "\nWarnings (" . count($total_warnings) . "):\n  " . implode("\n  ", $total_warnings) . "\n";
}
echo $check_only ? "Check finished (nothing written)\n" : "Done\n";
exit($total_warnings ? 1 : 0);
