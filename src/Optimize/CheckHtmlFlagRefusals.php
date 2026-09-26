<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA_Log;

/** Item forms (aa[...] input - filler.php, {editable} and {ajax} on site pages) which asked for HTML
 *  in a field without the HTML/plain switch or the rich-text widget in the last 30 days, sent by someone
 *  who does not edit the slice (AA\IO\Grabber\Form) - the value was stored as plain text, the field
 *  default. Logged once a day per slice and field. Index-only.
 */
class CheckHtmlFlagRefusals extends AbstractOptimize
{
    /** log.type of the rows - selector slice_id:field_id, params user:new|update|insert */
    public const EVENT = 'HTML_DENY';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Form input asked for HTML in plain-text fields');
    }

    public static function description(): string
    {
        return _m('Lists the fields whose form input asked for HTML in the last 30 days, although the field has neither the HTML/plain switch nor the rich-text widget and the sender does not edit the slice, so the value was stored as plain text.');
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
            [$user, $mode]         = array_pad(ParamExplode((string) $row['params']), 2, '');
            $slice_name = (string) ($slice_names[$slice_id] ?? '') ?: $slice_id;
            $last       = date('Y-m-d', (int) $row['last']);
            $findings[] = new Finding(
                Finding::WARNING,
                _m('A form asked for HTML in the plain-text field %1 of slice %2 (last %3)', [$field_id, $slice_name, $last]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Slice')         => safe($slice_name),
                    _m('Field')         => safe($field_id),
                    // the params of one of the rows (LogOnce::recent() takes the MAX)
                    _m('User (one of)') => safe($user),
                    _m('Item mode')     => safe($mode),
                    _m('Days seen')     => (string) (int) $row['days'],
                    _m('Last')          => $last,
                ]],
                advice: _m('Posted HTML was stored as plain text (or, added to a value of the other format, not stored at all). Either a form of the site sends HTML for this field - turn on "Show HTML/plain switch" in the field settings or use the rich-text editor as its widget - or the request was forged.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No form asked for HTML in a plain-text field in the last 30 days'))];
    }
}
