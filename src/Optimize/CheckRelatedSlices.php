<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA_Module;
use AA_Slice;

/** Editors of a slice may edit the items its related-item fields point to (IsItemEditable()), with
 *  no role in the target slice. A field pointing to a slice of another website - typically a copied
 *  template still pointing to the original's photos - lets them edit that website's items too.
 */
class CheckRelatedSlices extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Related slices of another website');
    }

    public static function description(): string
    {
        return _m('Lists related-item fields (rim with the edit button, mng) pointing to a slice that no website of their own slice uses. The editors of the slice can edit the items of that slice.');
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
        $links = [];
        $sites = [];
        foreach (DB_AA::select([], 'SELECT LOWER(HEX(`slice_id`)) AS sid, `id` FROM `field`', [['input_show_func', '#sLiCe-', 'CONTAIN']]) as $row) {
            $sid    = (string) $row['sid'];
            $target = AA_Slice::isStoredSlice($sid) ? RelatedItemEditTarget(AA_Slice::getOwnModule($sid)?->getWidget($row['id'])) : '';
            if (!$target || ($target === $sid)) {
                continue;
            }
            $links[] = ['slice' => $sid, 'field' => (string) $row['id'], 'target' => $target];
            $sites[$sid]    ??= AA_Module::belongsToSites($sid);
            $sites[$target] ??= AA_Module::belongsToSites($target);
        }
        return self::findingsFor($links, $sites, static fn(string $module_id, bool $link): string => AA_Slice::getModuleNameSafe($module_id, $link));
    }

    /**
     * @param list<array{slice: string, field: string, target: string}> $links  fields of a slice editing another slice
     * @param array<string, string[]>                                   $sites  module id => the websites it belongs to
     * @param \Closure(string, bool): string                            $name   module id, as a link => its name, HTML-escaped
     * @return Finding[]
     */
    public static function findingsFor(array $links, array $sites, \Closure $name): array
    {
        $evidence = [];
        $unknown  = 0;
        foreach ($links as $link) {
            $source_sites = $sites[$link['slice']] ?? [];
            if (!$source_sites) {
                $unknown++;         // no website to compare with
                continue;
            }
            if (array_intersect($source_sites, $sites[$link['target']] ?? [])) {
                continue;
            }
            $evidence[] = [
                _m('Slice')          => $name($link['slice'], true),
                _m('Field')          => safe($link['field']),
                _m('Related slice')  => $name($link['target'], true),
                _m('Its websites')   => implode(', ', array_map(static fn(string $site): string => $name($site, false), $source_sites)),
            ];
        }
        if (!$evidence) {
            // a slice no website uses is often shown by view.php or SSI - its fields stay unjudged, not "fine"
            return [new Finding(Finding::OK, $unknown
                ? _m('No related-item field points to a slice of another website; %1 fields of slices no website uses were not compared', [$unknown])
                : _m('Every related-item field points to a slice of its own website'))];
        }
        return [new Finding(
            Finding::INFO,
            _m('%1 related-item fields point to a slice of another website', [count($evidence)]),
            evidence: $evidence,
            advice: _m('The editors of the slice can edit the items of the related slice. If the field was copied with a template, point it to the slice of its own website.'),
        )];
    }
}
