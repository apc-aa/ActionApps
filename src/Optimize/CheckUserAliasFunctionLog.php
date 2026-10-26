<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\Adminlink;
use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA\Util\UserAliasFunction;
use AA_Log;

/** f_u calls pages actually made in the last 30 days that were not plain usr_* calls - the ALIAS_FU
 *  log rows AA_Item::f_u() writes, one a day per level, slice, field and function (LogOnce). Covers the
 *  field definitions and the templates ({alias:<field>:f_u:..}) alike. An installation function still
 *  ran (warning: rename it to usr_*); anything else gave nothing (error). Index-only, hence basic.
 */
class CheckUserAliasFunctionLog extends AbstractOptimize
{
    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('f_u calls on pages');
    }

    public static function description(): string
    {
        return _m('Lists the f_u alias calls of the last 30 days that AA refused (PHP or AA functions, undefined ones) or still made only for compatibility (installation functions without the usr_ prefix), by field and slice.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
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
        $rows = LogOnce::recent(UserAliasFunction::LOG_EVENT, now() - self::WINDOW_SECONDS, self::GROUPS);
        $ids  = [];
        foreach ($rows as $row) {
            $slice_id = UserAliasFunction::parseLogSelector((string) $row['selector'])['slice_id'];
            if (is_long_id($slice_id)) {
                $ids[$slice_id] = $slice_id;
            }
        }
        $names = $ids ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', array_values($ids), 'l']]) : [];
        return self::findingsFor($rows, $names);
    }

    /**
     * @param list<array<string, ?string>> $rows   LogOnce::recent() rows
     * @param array<string, string>        $names  slice id => name
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $names): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            $call     = UserAliasFunction::parseLogSelector($selector);
            [$reason, $file] = array_pad(ParamExplode((string) $row['params']), 2, '');
            $slice    = (string) ($names[$call['slice_id']] ?? '') ?: $call['slice_id'];
            $links    = [_m('Log rows') => AA_Log::getAdminUrl(UserAliasFunction::LOG_EVENT, $selector)];
            if (is_long_id($call['slice_id']) && $call['field_id'] !== '') {
                $links[_m('Field settings')] = Adminlink::fieldUrl((string) $call['slice_id'], (string) $call['field_id']);
            }
            $findings[] = new Finding(
                CheckUserAliasFunctions::severity($call['level'], $reason),
                CheckUserAliasFunctions::title($call['level'], $reason, $call['name'], $file) . ' - ' . _m('field %1 of slice %2', [$call['field_id'], $slice]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Slice')          => safe($slice),
                    _m('Field')          => safe($call['field_id']),
                    _m('File')           => safe($file),     // where the function is defined, or where the call threw
                    _m('Days seen')      => (string) (int) $row['days'],
                    _m('Last')           => date('Y-m-d', (int) $row['last']),
                ]],
                advice: CheckUserAliasFunctions::advice($call['level'], $reason, $call['name'], $file)
                    . ' ' . _m('The call is in the definition of the field or in a template using {alias:<field>:f_u:..}.'),
                links: $links,
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('Every f_u call of the last 30 days called a usr_* function'))];
    }
}
