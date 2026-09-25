<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA\Widget\AjaxSignature;
use AA_Log;

/** {ajax} edits whose widget type and properties Get_Widget ignored in the last 30 days because they
 *  were not signed (AA\Widget\AjaxSignature) - logged once a day per slice and field. A page cached
 *  before the signing edits with the field's own widget until it is regenerated; a lasting row or a
 *  bad signature means a forged request. Index-only.
 */
class CheckAjaxUnsigned extends AbstractOptimize
{
    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Unsigned {ajax} widget settings');
    }

    public static function description(): string
    {
        return _m('Lists the fields whose {ajax} edits came without a valid signature of their widget type and properties in the last 30 days, so AA used the field\'s own widget instead.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'security';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $rows   = LogOnce::recent(AjaxSignature::EVENT, now() - self::WINDOW_SECONDS, self::GROUPS);
        $slices = [];
        foreach ($rows as $row) {
            $slice = (string) ParamExplode((string) $row['selector'])[0];
            if (is_long_id($slice)) {
                $slices[$slice] = $slice;
            }
        }
        $slice_names = $slices ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', array_values($slices), 'l']]) : [];
        return self::findingsFor($rows, $slice_names);
    }

    /**
     * @param list<array<string, ?string>> $rows         LogOnce::recent() rows
     * @param array<string, string>        $slice_names  slice id => name
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $slice_names): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$slice_id, $field_id] = array_pad(ParamExplode($selector), 2, '');
            $slice_name = (string) ($slice_names[$slice_id] ?? '') ?: $slice_id;
            $findings[] = new Finding(
                Finding::WARNING,
                _m('{ajax} edit of field %1 in slice %2 came without a valid signature', [$field_id, $slice_name]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Slice')     => safe($slice_name),
                    _m('Field')     => safe($field_id),
                    _m('Reason')    => safe((string) $row['params']),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('The page sent a widget type or widget properties (5th and 6th parameter of {ajax}) without the signature AA adds since this version, so the field was edited with its own widget. Pages in the page cache stay unsigned until regenerated - flush the cache of the site. If the rows go on after that, someone sends forged requests - or, for a bad signature, the page is in another charset than the slice.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(AjaxSignature::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No unsigned {ajax} widget settings in the last 30 days'))];
    }
}
