<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;

/** A slice's settings fields (id `_...`) are filled on its settings page, never by the item form, so a
 *  required one can stay empty unnoticed - e.g. after an app install that had no default for it. The
 *  templates reading it then show nothing. One query finds the required settings fields, one reads the
 *  values of just those. */
class CheckRequiredSettings extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Required slice settings');
    }

    public static function description(): string
    {
        return _m('Warns about slices whose required settings fields have no value.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'db';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $required = DB_AA::select([], "SELECT LOWER(HEX(f.slice_id)) AS slice_id, f.id, f.name, m.name AS slice FROM `field` f INNER JOIN `module` m ON m.id = f.slice_id WHERE f.required = 1 AND LEFT(f.id, 1) = '_' AND m.deleted = 0");
        if (!$required) {
            return [new Finding(Finding::OK, _m('No slice has a required settings field'))];
        }
        $filled = [];
        $values = DB_AA::select([], 'SELECT LOWER(HEX(item_id)) AS item_id, field_id, text, flag FROM `content`', [
            ['item_id', array_values(array_unique(array_column($required, 'slice_id'))), 'l'],
            ['field_id', array_values(array_unique(array_column($required, 'id')))],
        ]);
        foreach ($values as $v) {
            // a number is a value (0 too); a text only when not empty
            if ((((int) $v['flag']) & FLAG_TEXT_STORED) === 0 || ((string) $v['text'] !== '')) {
                $filled[$v['item_id'] . $v['field_id']] = true;
            }
        }
        $empty = [];
        foreach ($required as $f) {
            if (!isset($filled[$f['slice_id'] . $f['id']])) {
                $empty[$f['slice_id']][] = $f;
            }
        }
        $findings = [];
        foreach ($empty as $slice => $fields) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('Slice "%1" has %2 required settings without a value', [(string) $fields[0]['slice'], count($fields)]),
                count: count($fields),
                evidence: array_map(static fn(array $f): array => [_m('Field') => safe((string) $f['id']), _m('Name') => safe((string) $f['name'])], $fields),
                advice: _m('Fill them in on the Setting page of the slice - templates reading them show nothing until then.'),
                links: [_m('Setting') => get_admin_url('slicefieldsedit.php', ['edit' => 1, 'change_id' => $slice], false)],
                key: $slice,
            );
        }
        return $findings ?: [new Finding(Finding::OK, _m('All %1 required settings fields have a value', [count($required)]))];
    }
}
