<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\Adminlink;
use AA_Moduleregistry;
use AA_Slice;

/** A related-item field offers the items of the slice its `#sLiCe-<id>` names. An id of another module (an
 *  Alerts module named like the newsletter slice) or of no module at all (a removed slice, a slice of another
 *  AA) offers nothing - the editor sees an empty list and saving the item may empty the field. A deleted slice
 *  still has its items, so a field pointing to one works.
 */
class CheckRelatedFieldsWithoutSlice extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Related-item fields without a slice');
    }

    public static function description(): string
    {
        return _m('Lists related-item fields which should offer the items of a module that is not a slice - an Alerts module, or a module missing in this AA (a removed slice, a slice of another installation). Such a field offers nothing to choose.');
    }

    /** a LIKE scan over all field definitions */
    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $types  = DB_AA::select(['unpackid' => 'type'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `type` FROM `module`');
        $stored = [];   // module id => a stored slice
        $fields = [];
        foreach (DB_AA::select([], 'SELECT LOWER(HEX(`slice_id`)) AS sid, `id` FROM `field`', [['input_show_func', '#sLiCe-', 'CONTAIN']]) as $row) {
            $sid = (string) $row['sid'];
            if (!($stored[$sid] ??= AA_Slice::isStoredSlice($sid))) {
                continue;   // the field of a deleted slice is not filled in
            }
            $target = strtolower((string) AA_Slice::getOwnModule($sid)?->getWidget($row['id'])?->getConstSlice());
            $type   = self::moduleType($types, $target);
            if (($target !== '') && ($type !== 'S')) {
                $fields[] = ['slice' => $sid, 'field' => (string) $row['id'], 'target' => $target, 'type' => is_null($type) ? null : ((string) AA_Moduleregistry::info($type, 'name') ?: $type)];
            }
        }
        return self::findingsFor($fields, static fn(string $module_id, bool $link): string => AA_Slice::getModuleNameSafe($module_id, $link));
    }

    /**
     * @param array<string, ?string> $types module id => module.type
     * @return ?string the module type - 'S' for a slice, also an old one with no type; null for no such module
     */
    public static function moduleType(array $types, string $module_id): ?string
    {
        return array_key_exists($module_id, $types) ? ((string) $types[$module_id] ?: 'S') : null;
    }

    /**
     * @param list<array{slice: string, field: string, target: string, type: ?string}> $fields fields whose items come
     *        from no slice; type - the name of the target's module type, null when there is no such module
     * @param \Closure(string, bool): string $name module id, as a link => its name, HTML-escaped
     * @return Finding[]
     */
    public static function findingsFor(array $fields, \Closure $name): array
    {
        if (!$fields) {
            return [new Finding(Finding::OK, _m('Every related-item field offers the items of a slice'))];
        }
        $evidence = [];
        foreach ($fields as $field) {
            $evidence[] = [
                _m('Slice')    => $name($field['slice'], true),
                _m('Field')    => a_href(Adminlink::fieldUrl($field['slice'], $field['field']), safe($field['field'])),
                _m('Items of') => is_null($field['type'])
                    ? safe($field['target']) . ' (' . safe(_m('no such module')) . ')'
                    : $name($field['target'], true) . ' (' . safe($field['type']) . ')',
            ];
        }
        return [new Finding(
            Finding::WARNING,
            _m('%1 related-item fields should offer the items of a module that is not a slice', [count($evidence)]),
            evidence: $evidence,
            advice: _m('The field offers nothing to choose and saving an item may empty it. In the field settings choose the slice whose items the field holds.'),
        )];
    }
}
