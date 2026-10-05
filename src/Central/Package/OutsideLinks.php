<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** The links of an app to modules outside it (ReferenceLint::MODULE_OUTSIDE) as they are on the target.
 *  A link to a module this AA has keeps working; one to a module it lacks points nowhere - unless the
 *  preview sent it to a slice of this AA (ImportChoices::$links), which the plan maps as it maps the ids
 *  of the app. */
final readonly class OutsideLinks
{
    /** I: the module outside the app is one of this AA too - the link keeps going to it; ref = its id */
    public const HERE = 'module-here';
    /** I: the preview sent the link to a slice of this AA; ref = "id => slice" */
    public const LINKED = 'module-linked';

    /**
     * @param array<string, string> $links   module outside the app => slice of this AA its links go to, as planned
     * @param array<string, true>   $here    the modules of this AA, lower case
     * @param array<string, true>   $outside the modules outside the app it links to, lower case - all of them,
     *                                       though the findings keep only some of a code
     */
    public function __construct(public array $links = [], private array $here = [], private array $outside = []) {}

    /** The findings with the links of MODULE_OUTSIDE that work here as I - slice.mlxctrl of a module here
     *  not: the writer empties it (RowWriter). A `+N more` of the findings left out is I when every module
     *  outside is here or linked.
     *  @param list<array{string, LintFinding}> $findings @return list<array{string, LintFinding}> */
    public function classify(array $findings): array
    {
        $all_work = !array_diff_key($this->outside, $this->here, $this->links);
        $out      = [];
        foreach ($findings as [$path, $f]) {
            $id = strtolower($f->ref);
            if (($f->code === ReferenceLint::MODULE_OUTSIDE) && ($f->level === LintFinding::WARNING)) {
                $f = match (true) {
                    isset($this->links[$id])                                      => new LintFinding(LintFinding::INFO, self::LINKED, "$id => {$this->links[$id]}", $f->offset),
                    isset($this->here[$id]) && !str_ends_with($path, '/mlxctrl') => new LintFinding(LintFinding::INFO, self::HERE, $f->ref, $f->offset),
                    $all_work && str_starts_with($f->ref, '+')                    => new LintFinding(LintFinding::INFO, self::HERE, $f->ref, $f->offset),
                    default                                                       => $f,
                };
            }
            $out[] = [$path, $f];
        }
        return $out;
    }

    /** The modules outside the app this AA lacks, linked to a slice or not - the preview offers a slice for
     *  each - with the places of the findings that link to them.
     *  @param list<array{string, LintFinding}> $findings of classify()
     *  @return array<string, list<string>> id => paths */
    public function missing(array $findings): array
    {
        $missing = array_fill_keys(array_keys(array_diff_key($this->outside, $this->here)), []);
        foreach ($findings as [$path, $f]) {
            $id = strtolower(substr($f->ref, 0, 32));
            if (in_array($f->code, [ReferenceLint::MODULE_OUTSIDE, self::LINKED], true) && isset($missing[$id])) {
                $missing[$id][$path] = true;
            }
        }
        return array_map(static fn(array $paths): array => array_map('strval', array_keys($paths)), $missing);
    }
}
