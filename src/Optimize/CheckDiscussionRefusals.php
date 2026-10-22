<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA_Log;

/** Comments that filldisc.php refused in the last 30 days because the item's slice shows no discussion:
 *  it has no discussion view of its own and none set in "Show discussion" of its fulltext. Comments to such
 *  slices were spam nobody saw; a site that shows them through a view of another slice needs the setting.
 *  Logged once a day per slice (LogOnce). Index-only, hence basic.
 */
class CheckDiscussionRefusals extends AbstractOptimize
{
    /** log.type of the refusal rows - selector the slice, params the reason */
    public const EVENT = 'DISC_DENY';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Refused comments');
    }

    public static function description(): string
    {
        return _m('Lists the slices whose new discussion comments were refused in the last 30 days because the slice shows no discussion.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'content';
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
                Finding::WARNING,
                _m('New comments to items of slice %1 were refused', [$slice_name]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Slice')     => safe($slice_name),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('The slice has no discussion view and none is set in "Show discussion" of its fulltext design, so a comment would not be shown anywhere. If the site shows the comments through a discussion view of another slice, choose that view in "Show discussion"; otherwise remove the comment form from the slice templates.'),
                // "Show discussion" is a setting of the fulltext design
                links: (is_long_id($slice_id) ? [_m('Fulltext design') => get_admin_url('se_fulltext.php', ['change_id' => $slice_id])] : []) + [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $slice_id)],
                key: $slice_id,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No comment was refused in the last 30 days'))];
    }
}
