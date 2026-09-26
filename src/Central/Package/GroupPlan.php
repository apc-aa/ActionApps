<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;

/** The constant groups of an import plan: their names on the target and
 *  the charset of their constants. Core groups are not mapped.
 *  Names: kept when free, else `name_1`, `_2`, ... cut to 16 bytes as CopyConstants() - never merged. Taken =
 *  on the target (ImportTarget::groupTaken()), given earlier in the plan, or another group of the package,
 *  compared as strtolower(rtrim()) like the collation of lt_groupNames. A new name is [A-Za-z0-9_.-] (it is
 *  spliced into templates); a non-ASCII name is renamed even when free - its bytes would differ between
 *  constant.group_id and field.input_show_func of modules of other charsets.
 *  Charset: the export rule of Collector::groups() - the owner's, else the first module naming it (a slice
 *  preferred), in package order; a group no module names: the first slice, else utf-8. */
final class GroupPlan
{
    /** I: a constant group gets another name on the target; ref = "old => new" */
    public const RENAMED = 'group-renamed';
    /** E: no free name for a group (MAX_SUFFIX suffixes taken) */
    public const EXHAUSTED = 'group-name-exhausted';

    /** the suffixes tried for a taken group name */
    public const MAX_SUFFIX = 999;

    /** a new group name (IdMap::NEW_GROUP_RE) - other characters become `_` */
    private const GROUP_CHARS = '/[^A-Za-z0-9_.-]/';

    /** @var array<string, true> normalised group names of the package */
    private array $names = [];
    /** @var array<string, true> normalised names the plan gave */
    private array $chosen = [];

    public function __construct(private readonly ImportTarget $target, private readonly LintTexts $texts) {}

    /** @return array<string, string> source name => target name (IdMap groups) */
    public function names(Package $package, FindingList $findings): array
    {
        $this->names  = array_fill_keys(array_map(static fn($g): string => self::norm($g->name), $package->constantGroups), true);
        $this->chosen = [];
        $map          = [];
        foreach ($package->constantGroups as $group) {
            $name = $group->name;
            if (in_array($name, IdMap::CORE_GROUPS, true)) {
                continue;       // on every AA - only referred to (Validator's E for a package carrying one)
            }
            $new = (mb_check_encoding($name, 'ASCII') && $this->free($name, $name)) ? $name : $this->rename($name);
            if ($new === null) {
                $findings->add("constant-group[$name]", new LintFinding(LintFinding::ERROR, self::EXHAUSTED, substr($name, 0, ReferenceLint::MAX_REF), 0));
                continue;
            }
            $this->chosen[self::norm($new)] = true;
            $map[$name]                     = $new;
            if ($new !== $name) {
                $findings->add("constant-group[$name]", new LintFinding(LintFinding::INFO, self::RENAMED, substr("$name => $new", 0, ReferenceLint::MAX_REF), 0));
            }
        }
        return $map;
    }

    /** @param array<string, string> $charsets module id => charset @return array<string, string> group name => charset */
    public function charsets(Package $package, array $charsets): array
    {
        $users = [];            // group name => the module using it (Collector::groups())
        $types = [];
        foreach ($package->modules as $m) {
            $types[$m->id] = $m->type;
            foreach ($this->used($m) as $name) {
                if (!isset($users[$name]) || (($types[$users[$name]] !== Module::SLICE) && ($m->type === Module::SLICE))) {
                    $users[$name] = $m->id;
                }
            }
        }
        $first = array_search(Module::SLICE, $types, true);
        $ret   = [];
        foreach ($package->constantGroups as $group) {
            $module            = ($group->owner !== '') ? $group->owner : ($users[$group->name] ?? (($first === false) ? '' : $first));
            $ret[$group->name] = $charsets[$module] ?? 'utf-8';
        }
        return $ret;
    }

    /** the group names a module uses: field widgets and const views, then {constant(s)/options:<g>} in its texts
     *  @return list<string> */
    private function used(Module $m): array
    {
        $names = [];
        foreach ($m->rows as $element => $rows) {
            foreach ($rows as $row) {
                if (($name = ReferenceRewriter::rowGroup((string) $element, $row)) !== null) {
                    $names[] = $name;
                }
            }
        }
        $texts = iterator_to_array($this->texts->rows('', array_diff_key($m->rows, ['module' => 1]), true), false);
        array_push($texts, ...iterator_to_array(LintTexts::code(isset(Schema::SETTINGS_CLASSES[$m->type]) ? ObjectExport::values($m->type, $m->settings, $m->objects, '') : [], true), false));
        foreach ($texts as [, $text]) {
            RewriteFailed::check(preg_match_all(ReferenceRewriter::GROUP_RE, $text, $found));
            array_push($names, ...array_map('trim', $found[0]));
        }
        return array_values(array_filter($names, static fn(string $n): bool => IdMap::isAppGroup($n) && preg_match(IdMap::GROUP_RE, $n)));
    }

    /** the first free of: the name with other characters as `_` (if that differs), then `<base>_1`, `_2`, ...
     *  @return string|null null = none free */
    private function rename(string $name): ?string
    {
        $base = substr((string) (preg_replace(self::GROUP_CHARS . 'u', '_', $name) ?? preg_replace(self::GROUP_CHARS, '_', $name)), 0, 16);
        if (($base !== $name) && $this->free($base, $name)) {
            return $base;
        }
        for ($i = 1; $i <= self::MAX_SUFFIX; ++$i) {
            $suffix = "_$i";
            $new    = substr($base, 0, min(strlen($base) + strlen($suffix), 16) - strlen($suffix)) . $suffix;
            if ($this->free($new, $name)) {
                return $new;
            }
        }
        return null;
    }

    /** no core group, not given in this plan, no other group of the package (a group keeps its own), not on the target */
    private function free(string $name, string $own): bool
    {
        $norm = self::norm($name);
        return !in_array($name, IdMap::CORE_GROUPS, true) && !isset($this->chosen[$norm])
            && (($name === $own) || !isset($this->names[$norm])) && !$this->target->groupTaken($name);
    }

    /** a name as the collation of lt_groupNames compares it */
    private static function norm(string $name): string
    {
        return strtolower(rtrim($name));
    }
}
