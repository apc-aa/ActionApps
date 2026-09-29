<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;

/** The existing slice the preview of an install offers first for a slice of the package: the one slice of
 *  this AA of its role (slice.type - Items, Reader management, Code - Pages, ...: AA_Slice::getSliceTypes())
 *  that the user may edit. Module ids say nothing across AAs, the role does - and the importer sees the
 *  choice and may change it. It is then a choice like any other: stored with the preview, planned by
 *  ExistingSlices. */
final class SliceRoles
{
    /** I: the preview chose the existing slice by the role of the package slice; ref = the role */
    public const PRESELECTED = 'preselected-by-role';
    /** W: as PRESELECTED, a Reader Management slice - the views and fields of the app work on its live readers */
    public const PRESELECTED_READERS = 'preselected-reader-slice';
    /** I: the slice of the role cannot take the package slice (the plan into it has an E) - it stays new; ref = "role => id" */
    public const PRESELECT_DROPPED = 'preselect-dropped';

    /** Source slice => [the slice of this AA it goes into, the role]. None in a transfer - the items would go
     *  into a live slice - nor in a copy in the same AA, where the slice of the role may be the source itself.
     *  A role of no slice, of several slices here or of several slices of the package leaves the slice new.
     *  @return array<string, array{string, string}> */
    public static function preselect(Package $package, ImportTarget $target, bool $sameAa, bool $transfer): array
    {
        if ($sameAa || $transfer) {
            return [];
        }
        $byRole = [];
        foreach ($package->modules as $m) {
            $role = ($m->type === Module::SLICE) ? ValueCodec::bytes($m->rows['slice'][0]['type'] ?? '') : '';
            if ($role !== '') {
                $byRole[$role][] = $m;
            }
        }
        $chosen = [];
        foreach ($byRole as $role => $modules) {
            $slices = array_values(array_filter($target->slicesOfType((string) $role), $target->mayEditSlice(...)));
            if ((count($modules) === 1) && (count($slices) === 1)) {
                $chosen[strtolower($modules[0]->id)] = [$slices[0], (string) $role];
            }
        }
        return $chosen;
    }

    /** The plan with the preselected slices, less those it has an E for - planned again without them.
     *  @param callable(array<string, string>): ImportPlan $plan the plan with these targets
     *  @return array{ImportPlan, array<string, string>, list<array{string, LintFinding}>, array<string, string>}
     *          [the plan, its targets, the findings of the choice, source slice => the role it was chosen by] */
    public static function plan(Package $package, ImportTarget $target, bool $sameAa, bool $transfer, callable $plan): array
    {
        $chosen   = self::preselect($package, $target, $sameAa, $transfer);
        $into     = array_map(static fn(array $c): string => $c[0], $chosen);
        $result   = $plan($into);
        $findings = new FindingList();
        $refused  = [];
        foreach ($result->ofLevel(LintFinding::ERROR) as [$path]) {
            foreach (array_keys($chosen) as $old) {
                if (str_starts_with(strtolower($path), Module::SLICE . "[$old]")) {
                    $refused[$old] = true;
                }
            }
        }
        foreach ($chosen as $old => [$id, $role]) {
            if (isset($refused[$old])) {
                $findings->add(Module::SLICE . "[$old]", new LintFinding(LintFinding::INFO, self::PRESELECT_DROPPED, "$role => $id", 0));
            } elseif ($role === ItemLint::READER_SLICE) {
                $findings->add(Module::SLICE . "[$old]", new LintFinding(LintFinding::WARNING, self::PRESELECTED_READERS, $role, 0));
            } else {
                $findings->add(Module::SLICE . "[$old]", new LintFinding(LintFinding::INFO, self::PRESELECTED, $role, 0));
            }
        }
        if ($refused) {
            $into   = array_diff_key($into, $refused);
            $result = $plan($into);
        }
        return [$result, $into, $findings->all(), array_map(static fn(array $c): string => $c[1], array_diff_key($chosen, $refused))];
    }
}
