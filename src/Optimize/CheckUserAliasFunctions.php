<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\Adminlink;
use AA\IO\DB\DB_AA;
use AA\Util\UserAliasFunction;

/** Field alias definitions (field.alias1_func .. alias3_func) using f_u, sorted by what AA does with
 *  the function they name (UserAliasFunction::classify()): a usr_* function is fine; another function
 *  of the installation still runs but should be renamed to usr_*; anything else - PHP's own or AA's
 *  function, an undefined one, no function at all - is not called, the alias shows nothing.
 *  One finding per function name, the fields as evidence. f_u in templates ({alias:<field>:f_u:..})
 *  is not in the field table - CheckUserAliasFunctionLog shows what pages actually called.
 *  A scan of the whole field table, hence extended.
 */
class CheckUserAliasFunctions extends AbstractOptimize
{
    /** constant SQL - nothing from outside. Deleted modules and orphaned fields render nothing, they are
     *  left out. The LIKE is case-insensitive on AA's collation, as PHP's method call f_u / F_U is. */
    public const FIELDS_SQL = "SELECT LOWER(HEX(f.slice_id)) AS slice_id, f.id, f.alias1_func, f.alias2_func, f.alias3_func, m.name"
        . " FROM `field` AS f JOIN `module` AS m ON m.id = f.slice_id AND m.deleted = 0"
        . " WHERE f.alias1_func LIKE 'f\\_u:%' OR f.alias2_func LIKE 'f\\_u:%' OR f.alias3_func LIKE 'f\\_u:%'";

    /** evidence rows per finding */
    private const EVIDENCE_MAX = 20;

    public static function name(): string
    {
        return _m('f_u functions in field definitions');
    }

    public static function description(): string
    {
        return _m('Lists the field alias definitions using f_u whose function AA does not call (PHP or AA functions, undefined ones) or still calls only for compatibility (installation functions without the usr_ prefix).');
    }

    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    public static function category(): string
    {
        return 'errors';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        // include/item.php loads include/usr_aliasfnc.php and, through stringexpand.php, the custom
        // directory - the functions a page would find
        class_exists(\AA_Item::class);
        return self::findingsFor(DB_AA::select([], self::FIELDS_SQL));
    }

    /**
     * @param list<array<string, ?string>> $rows  FIELDS_SQL rows
     * @param ?string $base  the AA base directory, AA_BASE_PATH when null (tests)
     * @return Finding[]
     */
    public static function findingsFor(array $rows, ?string $base = null): array
    {
        $groups = [];
        $fine   = 0;
        foreach ($rows as $row) {
            foreach (['alias1_func', 'alias2_func', 'alias3_func'] as $slot) {
                $fnc = ParseFnc((string) ($row[$slot] ?? ''));
                if (strtolower($fnc['fnc']) !== 'f_u') {
                    continue;
                }
                $name    = (string) ParamExplode($fnc['param'])[0];
                $verdict = UserAliasFunction::classify($name, $base);
                if ($verdict['level'] === UserAliasFunction::USER) {
                    ++$fine;
                    continue;
                }
                $key = $verdict['level'] . ':' . $verdict['reason'] . ':' . $name;
                $groups[$key] ??= ['verdict' => $verdict, 'name' => $name, 'fields' => []];
                $groups[$key]['fields'][] = [
                    _m('Slice')      => safe((string) ($row['name'] ?? '') ?: (string) $row['slice_id']),
                    _m('Field')      => a_href(Adminlink::fieldUrl((string) $row['slice_id'], (string) $row['id']), safe((string) $row['id'])),
                    _m('Alias')      => safe(substr($slot, 0, 6)),
                    _m('Definition') => safe(mb_substr((string) $row[$slot], 0, 120)),
                ];
            }
        }

        $findings = [];
        foreach ($groups as $key => $group) {
            $verdict  = $group['verdict'];
            $findings[] = new Finding(
                self::severity($verdict['level'], $verdict['reason'], true),
                self::title($verdict['level'], $verdict['reason'], $group['name'], $verdict['file']),
                count: count($group['fields']),
                evidence: array_slice($group['fields'], 0, self::EVIDENCE_MAX),
                advice: self::advice($verdict['level'], $verdict['reason'], $group['name'], $verdict['file'], true),
                key: 'def:' . $key,
            );
        }
        if ($findings) {
            return CheckRunner::sort($findings);
        }
        return [new Finding(Finding::OK, $fine ? _m('All %1 f_u field definitions call usr_* functions', [$fine]) : _m('No field definition uses f_u'))];
    }

    /** what a non-usr_* f_u call means - shared with CheckUserAliasFunctionLog
     *  @param bool $static  a field definition (this check), not a call a page made: "undefined" is only a
     *                       warning there - the admin page does not load what only a site loads (a site
     *                       control file), the runtime log is the authority */
    public static function severity(string $level, string $reason, bool $static = false): string
    {
        return match (true) {
            $level === UserAliasFunction::INSTALLATION              => Finding::WARNING,
            $reason === UserAliasFunction::EMPTY                    => Finding::INFO,     // showed nothing before, too
            $static && $reason === UserAliasFunction::UNDEFINED     => Finding::WARNING,
            default                                                  => Finding::ERROR,
        };
    }

    /** one plain-text sentence - the renderer escapes it */
    public static function title(string $level, string $reason, string $name, string $file = ''): string
    {
        // every text spelled out - AA\Lang\MsgExtractor skips an _m() whose first argument is not a literal
        return match (true) {
            $level === UserAliasFunction::INSTALLATION  => _m('f_u calls %1, an installation function without the usr_ prefix', [$name]),
            $reason === UserAliasFunction::EMPTY        => _m('f_u without a function name'),
            $reason === UserAliasFunction::UNDEFINED    => _m('f_u names %1, which is not defined', [$name]),
            $reason === UserAliasFunction::INTERNAL     => _m('f_u names %1, a PHP function - not called', [$name]),
            $reason === UserAliasFunction::CORE         => _m('f_u names %1, a function of AA itself (%2) - not called', [$name, $file]),
            $reason === UserAliasFunction::FOREIGN      => _m('f_u names %1, defined outside AA in %2 - not called', [$name, $file]),
            $reason === UserAliasFunction::CALL_FAILED  => _m('f_u call of %1 failed', [$name]),
            default                                      => _m('f_u names "%1", which is not a function name - not called', [$name]),
        };
    }

    /** @param bool $static  see severity() */
    public static function advice(string $level, string $reason, string $name, string $file, bool $static = false): string
    {
        return match (true) {
            $level === UserAliasFunction::INSTALLATION  => _m('AA still calls it so that old sites keep working, but f_u is meant for usr_* functions only. Rename the function to usr_%1 (defined in %2) and change the definitions.', [$name, $file]),
            $reason === UserAliasFunction::EMPTY        => _m('The alias shows nothing (it never did). Remove it or name a usr_* function.'),
            $static && $reason === UserAliasFunction::UNDEFINED => _m('The function is not defined on this admin page. A site may still define it (a site control file, a file only its pages load) - the check "f_u calls on pages" says what pages really called; trust that one. If it is not there either, define usr_%1 in include/usr_aliasfnc.php or remove the alias.', [$name]),
            $reason === UserAliasFunction::UNDEFINED    => _m('The page had no such function, the alias showed nothing. Define usr_%1 in include/usr_aliasfnc.php or remove the alias.', [$name]),
            $reason === UserAliasFunction::FOREIGN      => _m('Only functions of the installation are called: usr_* ones, ones defined by include/usr_aliasfnc.php (and the files it includes), include/custom/ or modules/site/sites/. Define the function there as usr_%1.', [$name]),
            $reason === UserAliasFunction::CALL_FAILED  => _m('The call failed: %1. Either the function does not take what f_u passes (array content, string field id, string parameters) or it failed inside. Until it is fixed the alias shows nothing.', [$file]),
            default                                      => _m('The alias shows nothing: f_u calls only functions of the installation (usr_* in include/usr_aliasfnc.php), never PHP or AA functions. Write a usr_* function that does what is needed, or use an AA expression instead.'),
        };
    }
}
