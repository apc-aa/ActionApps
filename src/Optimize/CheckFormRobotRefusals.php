<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA_Log;

/** New items of aa[...] forms that filler.php refused in the last 30 days as robots: the form posted an empty
 *  slice_id. {formantispam:<slice_id>} and hand-made forms leave it empty and fill it by JS on the first move
 *  of the mouse or key, which robots do not do. Mostly spam; a real visitor refused means the JS of the form
 *  did not run. Logged once a day per slice (LogOnce). Index-only, hence basic.
 */
class CheckFormRobotRefusals extends AbstractOptimize
{
    /** log.type of the refusal rows - selector the slice, params the page with the form */
    public const EVENT = 'FORM_ROBOT';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Forms refused as robots');
    }

    public static function description(): string
    {
        return _m('Lists the slices whose new items from a form were refused in the last 30 days because the form posted an empty slice_id, which its JS fills only for a visitor who moves the mouse or presses a key.');
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
        $rows   = LogOnce::recent(self::EVENT, now() - self::WINDOW_SECONDS, self::GROUPS);
        $slices = array_values(array_filter(array_map(static fn(array $row): string => (string) $row['selector'], $rows), 'is_long_id'));
        $slice_names = $slices ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', $slices, 'l']]) : [];
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
            $slice_id   = (string) $row['selector'];
            $slice_name = (string) ($slice_names[$slice_id] ?? '') ?: $slice_id;
            $findings[] = new Finding(
                Finding::INFO,
                _m('A form adding items to slice %1 was refused as a robot', [$slice_name]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Slice')     => safe($slice_name),
                    _m('Page')      => safe((string) $row['params']),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('The form posted an empty slice_id. Forms with {formantispam:<slice_id>} or with their own anti-robot JS leave it empty and fill it when the visitor moves the mouse or presses a key, so this is mostly spam and needs no action. If real visitors complain that the form does not save, check that the JS of the form runs on the page (the browser console) and fills slice_id.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $slice_id)],
                key: $slice_id,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No form was refused as a robot in the last 30 days'))];
    }
}
