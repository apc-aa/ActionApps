<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA_Log;

/** Alert sends of an item that AA refused in the last 30 days: AA_Responder_Alertitem answers error 9
 *  when the collection is not related to the item's slice (AA_Collection::relatesToSlice()) and logs it
 *  once a day per collection, slice and user (AA_Collection::logRefusal(), LogOnce). A send button in
 *  an old slice template naming an unrelated collection, or someone trying to mail another slice's
 *  readers. Index-only, hence basic.
 */
class CheckAlertitemRefusals extends AbstractOptimize
{
    /** log.type of the refusal rows - selector collection:slice:user, params the item of the day's first
     *  refusal (the evidence shows the highest of them - LogOnce::recent() takes MAX(params)) */
    public const EVENT = 'ALERT_DENY';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Refused alert sends');
    }

    public static function description(): string
    {
        return _m('Lists the alert sends of an item refused in the last 30 days because the collection is not related to the item\'s slice (no filter on the slice, not its reader slice, no common site).');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'mail';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $rows        = LogOnce::recent(self::EVENT, now() - self::WINDOW_SECONDS, self::GROUPS);
        $collections = $slices = [];
        foreach ($rows as $row) {
            [$collection, $slice] = array_pad(ParamExplode((string) $row['selector']), 2, '');
            $collections[$collection] = $collection;
            if (is_long_id($slice)) {
                $slices[$slice] = $slice;
            }
        }
        $collection_names = $collections ? DB_AA::select(['id' => 'name'], 'SELECT c.id, m.name FROM `alerts_collection` AS c JOIN `module` AS m ON m.id = c.module_id', [['c.id', array_values($collections)]]) : [];
        $slice_names      = $slices ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', array_values($slices), 'l']]) : [];
        return self::findingsFor($rows, $collection_names, $slice_names);
    }

    /**
     * @param list<array<string, ?string>> $rows              LogOnce::recent() rows
     * @param array<string, string>        $collection_names  collection id => name of its Alerts module
     * @param array<string, string>        $slice_names       slice id => name
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $collection_names, array $slice_names): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$collection, $slice_id, $user] = array_pad(ParamExplode($selector), 3, '');
            $collection_name = (string) ($collection_names[$collection] ?? '') ?: $collection;
            $slice_name      = (string) ($slice_names[$slice_id] ?? '') ?: $slice_id;
            $findings[] = new Finding(
                Finding::WARNING,
                _m('Alert send of an item of slice %1 through collection %2 was refused', [$slice_name, $collection_name]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Collection') => safe($collection_name),
                    _m('Slice')      => safe($slice_name),
                    _m('User')       => safe($user),
                    _m('Item')       => safe((string) $row['params']),   // an example: MAX over the days
                    _m('Days seen')  => (string) (int) $row['days'],
                    _m('Last')       => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('An editor of the slice sent an item through a collection that has no filter on the slice, whose reader slice is not the slice and that shares no site with it. If the send is meant, add a filter on the slice to the collection (a real send to the readers needs one anyway) or add the slice and the collection\'s reader slice to one site. Otherwise fix the send button in the slice templates, or find out who tried to mail the readers of another collection.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No alert send was refused in the last 30 days'))];
    }
}
