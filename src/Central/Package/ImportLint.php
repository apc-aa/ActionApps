<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Package;
use Generator;
use Throwable;

/** The reference lint of an import (design balicek-v1.md § 8.4; the Validator leaves it to the plan):
 *  ReferenceLint over the definition texts of the package as ExportLint reads them (LintTexts) - text
 *  columns of the rows, object properties that are code, spot conditions - with the identity map of the
 *  package: what the plan maps. Finds what the rewrite cannot map (computed views, unknown forms, glued
 *  ids) and what leads outside the package. W and I only - the preview reports them; the structured
 *  references (slice.vid, mlxctrl, emailid_*, ...) are the Validator's. RawBytes are linted as bytes:
 *  the importer writes and rewrites them too (ImportRewrite). Item values are not linted: article
 *  texts link anywhere. Pure. */
final class ImportLint
{
    private readonly LintTexts $texts;

    public function __construct(Schema $schema)
    {
        $this->texts = new LintTexts($schema);
    }

    /** @throws RewriteFailed a regex failed */
    public function check(Package $package, FindingList $into): void
    {
        $lint = new ReferenceLint(self::identity($package));
        foreach ($this->texts($package) as [$path, $text]) {
            foreach (LintTexts::lint($lint, $path, $text) as $finding) {
                $into->add($path, $finding);
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

    /** @return Generator<int, array{string, string}> [path, text] */
    private function texts(Package $package): Generator
    {
        foreach ($package->modules as $m) {
            $path = "$m->type[$m->id]";
            yield from $this->texts->rows($path, $m->rows, true);
            try {       // a malformed object is Validator's E
                yield from LintTexts::code(isset(Schema::SETTINGS_CLASSES[$m->type]) ? ObjectExport::values($m->type, $m->settings, $m->objects, $path) : [], true);
            } catch (Throwable) {
            }
            yield from LintTexts::conditions("$path/spot-tree", $m->spotTree['spots'] ?? null);
        }
        foreach ($package->constantGroups as $group) {
            yield from $this->texts->rows("constant-group[$group->name]", $group->rows, true);
        }
    }
}
