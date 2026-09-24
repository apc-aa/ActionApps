<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use Throwable;

/** The constant groups of an import plan (design balicek-v1.md § 5.3, § 7.1; Planner): their names on
 *  the target and the charset of their constants.
 *
 *  Names: kept when free, else `name_1`, `_2`, ... cut to 16 bytes as CopyConstants()
 *  (include/constedit_util.php:520-530) - never merged. Taken = on the target (ImportTarget::groupTaken(),
 *  which compares in the collation of lt_groupNames), given earlier in this plan, or another group name
 *  of the package; in the plan compared as strtolower(rtrim()) - as that collation does, so `Foo` and
 *  `foo` of one package do not meet in one group. A new name is [A-Za-z0-9_.-] (IdMap: it is spliced
 *  into templates) - other characters become `_`. A non-ASCII name is renamed even when free: its bytes
 *  would differ between constant.group_id (the group's charset) and field.input_show_func (each
 *  module's); so no non-ASCII name ever reaches groupTaken(). Core groups are not mapped.
 *
 *  Charset: the export rule of Collector::groups() - the owner's (a slice of the package); an unowned
 *  group has the charset of its user: the first module naming it in a field widget or in a text
 *  ({constant(s)/options:<g>} in rows other than `module` and in object code), a slice preferred.
 *  "First" is package order - the export walked the app in its own order, which the package does not
 *  keep; only an unowned group used by two slices of different charsets can differ. A group no module
 *  names (exported "unused"): the first slice, else utf-8. */
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

    /** the group names a module uses: field widgets, then {constant(s)/options:<g>} in its texts
     *  @return list<string> */
    private function used(Module $m): array
    {
        $names = [];
        foreach ($m->rows['field'] ?? [] as $row) {
            if (($name = ReferenceRewriter::inputShowFuncGroup(ValueCodec::bytes($row['input_show_func'] ?? ''))) !== null) {
                $names[] = $name;
            }
        }
        $texts = iterator_to_array($this->texts->rows('', array_diff_key($m->rows, ['module' => 1]), true), false);
        try {
            array_push($texts, ...iterator_to_array(LintTexts::code(isset(Schema::SETTINGS_CLASSES[$m->type]) ? ObjectExport::values($m->type, $m->settings, $m->objects, '') : [], true), false));
        } catch (Throwable) {       // a malformed object is Validator's E
        }
        foreach ($texts as [, $text]) {
            RewriteFailed::check(preg_match_all(ReferenceRewriter::GROUP_RE, $text, $found));
            array_push($names, ...array_map('trim', $found[0]));
        }
        return array_values(array_filter($names, static fn(string $n): bool => ($n !== '') && preg_match(IdMap::GROUP_RE, $n)
            && !str_starts_with($n, '#sLiCe-') && ($n[0] !== '[') && !in_array($n, IdMap::CORE_GROUPS, true)));
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
