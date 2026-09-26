<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Package;
use Generator;

/** The reference lint of an import: ReferenceLint over the definition texts of the package, with the
 *  package's own ids as the map. Finds what the rewrite cannot map (computed views, unknown forms, glued
 *  ids) and what leads outside the package - W and I only; the structured references are the Validator's.
 *  RawBytes are linted too: the importer rewrites them as well. Item values are not linted: article texts
 *  link anywhere. */
final class ImportLint
{
    private readonly LintTexts $texts;

    public function __construct(Schema $schema)
    {
        $this->texts = new LintTexts($schema);
    }

    /** @param Package|null $ids the package whose ids the rewrite maps - the whole one, when $package leaves
     *                           out what an existing slice keeps; null = $package
     *  @throws RewriteFailed a regex failed */
    public function check(Package $package, FindingList $into, ?Package $ids = null): void
    {
        $lint = new ReferenceLint(self::identity($ids ?? $package));
        foreach ($this->texts($package) as $entry) {
            foreach (LintTexts::lint($lint, ...$entry) as $finding) {
                $into->add($entry[0], $finding);
            }
        }
    }

    /** the ids of the package, each mapped to itself */
    private static function identity(Package $package): IdMap
    {
        $rows  = static function (string $element, string $column) use ($package): array {
            $values = [];
            foreach ($package->modules as $m) {
                array_push($values, ...array_map([ValueCodec::class, 'bytes'], array_column($m->rows[$element] ?? [], $column)));
            }
            return $values;
        };
        $longs = [];
        foreach ($package->modules as $m) {
            array_push($longs, ...ObjectExport::ids($m->objects), ...array_map(static fn(array $item): string => ValueCodec::bytes($item['id'] ?? ''), $m->items));
        }
        foreach ($package->constantGroups as $group) {
            array_push($longs, ...array_map([ValueCodec::class, 'bytes'], array_column($group->rows['constant'] ?? [], 'id')));
        }
        return LintTexts::identity(
            array_map(static fn($m): string => $m->id, $package->modules),
            $longs,
            $rows('view', 'id'),
            $rows('email', 'id'),
            array_map(static fn($g): string => $g->name, $package->constantGroups),
            $rows('alerts_collection', 'id'),
        );
    }

    /** @return Generator<int, array{0: string, 1: string, 2?: ?string}> [path, text, group of a group column] */
    private function texts(Package $package): Generator
    {
        foreach ($package->modules as $m) {
            $path = "$m->type[$m->id]";
            yield from $this->texts->rows($path, $m->rows, true);
            yield from LintTexts::code(isset(Schema::SETTINGS_CLASSES[$m->type]) ? ObjectExport::values($m->type, $m->settings, $m->objects, $path) : [], true);
            yield from LintTexts::conditions("$path/spot-tree", $m->spotTree['spots'] ?? null);
            yield from LintTexts::settingValues($path, $m->transfer['setting-values'] ?? null, true);
        }
        foreach ($package->constantGroups as $group) {
            yield from $this->texts->rows("constant-group[$group->name]", $group->rows, true);
        }
    }
}
